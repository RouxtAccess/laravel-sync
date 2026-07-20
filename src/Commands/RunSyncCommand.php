<?php

namespace Rouxtaccess\Sync\Commands;

use Illuminate\Console\Command;
use Rouxtaccess\Sync\Contracts\ProgressReporter;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\GroupStore;
use Rouxtaccess\Sync\Progress\LineProgressReporter;
use Rouxtaccess\Sync\Progress\PromptsProgressReporter;
use Rouxtaccess\Sync\Registries\AfterHookRegistry;
use Rouxtaccess\Sync\Registries\SyncTypeRegistry;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class RunSyncCommand extends Command
{
    protected const ADD_NEW = '__add_new__';

    protected $signature = 'rouxt:sync
        {group? : The configured group to sync (skips the picker)}
        {--yes : Run every job in the group without prompting}
        {--force : Run even in a non-allowed environment}';

    protected $description = 'Sync a group of jobs (database, files, S3) from an upstream source';

    public function handle(GroupStore $store, SyncTypeRegistry $registry): int
    {
        intro('Run a sync group');

        if (! $this->guardEnvironment()) {
            return self::FAILURE;
        }

        $upgraded = $store->migrate();

        if ($upgraded > 0) {
            note("Upgraded {$upgraded} job(s) in the store to the current format.");
        }

        $group = $this->resolveGroup($store, $registry);

        if ($group === null) {
            return self::FAILURE;
        }

        $jobs = $store->jobs($group);

        if ($jobs === []) {
            error("Group '{$group}' has no jobs.");

            return self::FAILURE;
        }

        $selected = $this->selectJobs($group, $jobs, $registry);

        if ($selected === []) {
            warning('No jobs selected.');

            return self::FAILURE;
        }

        $this->showPlan($selected, $registry);

        if (! $this->option('yes') && ! confirm('Run '.count($selected)." job(s) in '{$group}' now?", default: false)) {
            warning('Aborted.');

            return self::FAILURE;
        }

        return $this->runJobs($group, $selected, $registry, ! $this->option('yes'));
    }

    protected function guardEnvironment(): bool
    {
        $allowed = config('sync.guard.allowed_environments', ['local']);

        if ($this->laravel->environment($allowed)) {
            return true;
        }

        $environment = $this->laravel->environment();

        if (! $this->option('force')) {
            error("Refusing to run in the '{$environment}' environment. Allowed: ".implode(', ', $allowed).'. Pass --force to override.');

            return false;
        }

        warning("Running in the '{$environment}' environment because --force was passed.");

        return true;
    }

    protected function resolveGroup(GroupStore $store, SyncTypeRegistry $registry): ?string
    {
        $argument = $this->argument('group');

        if (filled($argument)) {
            if (! $store->has($argument)) {
                error("Group '{$argument}' is not configured. Run without an argument to add it.");

                return null;
            }

            return $argument;
        }

        $names = $store->names();

        $choice = select(
            label: 'Which group do you want to sync?',
            options: [...array_combine($names, $names), self::ADD_NEW => '＋ Add a new group…'],
            default: $names === [] ? self::ADD_NEW : $names[0],
        );

        if ($choice === self::ADD_NEW) {
            return $this->addGroup($store, $registry);
        }

        return $choice;
    }

    protected function addGroup(GroupStore $store, SyncTypeRegistry $registry): string
    {
        note('Add a new sync group.');

        $group = text(
            label: 'Group name',
            placeholder: 'e.g. production, staging',
            required: true,
            validate: fn (string $value): ?string => $store->has(trim($value)) ? 'A group with that name already exists.' : null,
            transform: fn (string $value): string => trim($value),
        );

        $jobs = [];

        do {
            $jobs[] = $this->addJob($registry, $jobs);
        } while (confirm('Add another job to this group?', default: false));

        $store->put($group, $jobs);
        note("Saved group '{$group}' with ".count($jobs).' job(s).');

        return $group;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @return array<string, mixed>
     */
    protected function addJob(SyncTypeRegistry $registry, array $existing): array
    {
        $typeKey = select(label: 'What kind of sync is this?', options: $registry->options());
        $type = $registry->get($typeKey);

        $takenNames = array_map(fn (array $job): string => $job['name'], $existing);

        $name = text(
            label: 'Job name',
            placeholder: 'e.g. db, files, assets',
            required: true,
            validate: fn (string $value): ?string => in_array(trim($value), $takenNames, true) ? 'A job with that name already exists in this group.' : null,
            transform: fn (string $value): string => trim($value),
        );

        $job = ['name' => $name, 'type' => $typeKey];

        $config = [];

        foreach ($type->fields() as $field) {
            $config[$field->key] = $this->askField($field, $config);
        }

        $job['config'] = $config;

        $applicableHooks = app(AfterHookRegistry::class)->applicableTo($job);

        if ($applicableHooks !== []) {
            $job['after'] = multiselect(
                label: 'After this job succeeds, offer to…',
                options: $applicableHooks,
                default: array_keys($applicableHooks),
            );
        }

        return $job;
    }

    /**
     * @param  array<int, array<string, mixed>>  $jobs
     * @return array<int, array<string, mixed>>
     */
    protected function selectJobs(string $group, array $jobs, SyncTypeRegistry $registry): array
    {
        $byName = collect($jobs)->keyBy(fn (array $job): string => $job['name']);

        if ($this->option('yes') || $byName->count() === 1) {
            return $byName->values()->all();
        }

        $selected = multiselect(
            label: "Which jobs in '{$group}' do you want to run?",
            options: $byName->map(fn (array $job): string => $this->jobLabel($job, $registry))->all(),
            default: $byName->keys()->all(),
            required: true,
        );

        return array_map(fn (string $name): array => $byName->get($name), $selected);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    protected function jobLabel(array $job, SyncTypeRegistry $registry): string
    {
        $type = $registry->has($job['type'] ?? '') ? $registry->get($job['type'])::label() : ($job['type'] ?? 'unknown');

        return "{$job['name']} — {$type}";
    }

    /**
     * @param  array<int, array<string, mixed>>  $jobs
     */
    protected function showPlan(array $jobs, SyncTypeRegistry $registry): void
    {
        foreach ($jobs as $job) {
            $rows = [['Job', $job['name']]];

            if ($registry->has($job['type'] ?? '')) {
                $rows = [...$rows, ...$registry->get($job['type'])->summary($job)];
            } else {
                $rows[] = ['Type', 'unknown ['.($job['type'] ?? '').']'];
            }

            table(['Setting', 'Value'], $rows);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $jobs
     */
    protected function runJobs(string $group, array $jobs, SyncTypeRegistry $registry, bool $interactive): int
    {
        $results = [];

        foreach ($jobs as $job) {
            $name = $job['name'];

            if (! $registry->has($job['type'] ?? '')) {
                error("Job '{$name}': unknown sync type [".($job['type'] ?? '').'].');
                $results[] = false;

                continue;
            }

            $type = $registry->get($job['type']);
            note("→ {$name} ({$type::label()})");

            $result = $type->run($job, $interactive, $this->progressReporter($interactive));
            $result->ok ? note("✓ {$result->message}") : error("✗ {$result->message}");
            $results[] = $result->ok;
        }

        $succeeded = count(array_filter($results));
        $summary = "Group '{$group}': {$succeeded}/".count($results).' job(s) succeeded.';

        if ($succeeded === count($results)) {
            outro($summary);

            return self::SUCCESS;
        }

        error($summary);

        return self::FAILURE;
    }

    /**
     * An interactive run on a TTY gets a live progress bar; otherwise (--yes or a
     * piped output) progress is appended as plain lines through the command's
     * output so it stays readable in logs.
     */
    protected function progressReporter(bool $interactive): ProgressReporter
    {
        if ($interactive && stream_isatty(STDOUT)) {
            return new PromptsProgressReporter;
        }

        return new LineProgressReporter(fn (string $message) => $this->line($message));
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    protected function askField(Field $field, array $answers): mixed
    {
        if ($field->options !== null) {
            return select(label: $field->label, options: $field->options, default: $field->textDefault($answers) ?: array_key_first($field->options));
        }

        if ($field->boolean) {
            return confirm(label: $field->label, default: $field->booleanDefault());
        }

        if ($field->secret) {
            return password(label: $field->label, required: $field->required);
        }

        return $field->cast(text(
            label: $field->label,
            placeholder: $field->placeholder ?? '',
            default: $field->textDefault($answers),
            required: $field->required,
            hint: $field->hint ?? '',
        ));
    }
}
