<?php

namespace Rouxtaccess\Sync\Hooks;

use Illuminate\Support\Facades\DB;
use Rouxtaccess\Sync\Concerns\ConnectsToImportedDatabase;
use Rouxtaccess\Sync\Contracts\AfterHook;

use function Laravel\Prompts\confirm;

class AnonymizeDatabaseHook implements AfterHook
{
    use ConnectsToImportedDatabase;

    public static function key(): string
    {
        return 'anonymize';
    }

    public static function label(): string
    {
        return 'Anonymize the imported database';
    }

    public function appliesToJob(array $job): bool
    {
        return str_starts_with($job['type'] ?? '', 'db-') && $this->anonymizers() !== [];
    }

    public function prompt(array $job, array $context, bool $interactive): bool
    {
        $database = $context['database'] ?? null;

        if (! $interactive || $database === null) {
            return false;
        }

        return confirm('After importing, run the configured anonymizers on '.$database.'?', default: false);
    }

    public function handle(array $job, array $context): string
    {
        return $this->onImportedDatabase($job, $context, function (string $connection): string {
            $count = 0;

            foreach ($this->anonymizers() as $anonymizer) {
                $this->apply($connection, $anonymizer);
                $count++;
            }

            return "Applied {$count} anonymizer(s) to {$connection}.";
        });
    }

    /**
     * A configured anonymizer is either a raw SQL statement or the class name of
     * an invokable action called with the connection name.
     */
    protected function apply(string $connection, mixed $anonymizer): void
    {
        if (is_string($anonymizer) && class_exists($anonymizer)) {
            app($anonymizer)($connection);

            return;
        }

        DB::connection($connection)->unprepared((string) $anonymizer);
    }

    /**
     * @return array<int, mixed>
     */
    protected function anonymizers(): array
    {
        return config('sync.anonymizers', []);
    }
}
