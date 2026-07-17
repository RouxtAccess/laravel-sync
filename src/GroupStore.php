<?php

namespace Rouxtaccess\Sync;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class GroupStore
{
    /**
     * Top-level job keys that are not part of a job's type-specific config.
     */
    protected const RESERVED = ['name', 'type', 'config', 'after'];

    public function __construct(
        protected string $path,
    ) {}

    /**
     * Every configured group keyed by name, with jobs normalized to the current
     * shape (name, type, config, after) so older flat stores read seamlessly.
     *
     * @return Collection<string, array{jobs: array<int, array<string, mixed>>}>
     */
    public function all(): Collection
    {
        if (! File::exists($this->path)) {
            return collect();
        }

        $decoded = json_decode(File::get($this->path), true);

        if (! is_array($decoded)) {
            return collect();
        }

        return collect($decoded)->map(fn ($group): array => $this->normalizeGroup($group));
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return $this->all()->keys()->all();
    }

    public function has(string $group): bool
    {
        return $this->all()->has($group);
    }

    /**
     * The jobs configured for a group.
     *
     * @return array<int, array<string, mixed>>
     */
    public function jobs(string $group): array
    {
        return data_get($this->all()->get($group), 'jobs', []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $jobs
     */
    public function put(string $group, array $jobs): void
    {
        $jobs = array_map(fn ($job): array => $this->normalizeJob($job), array_values($jobs));

        $groups = $this->all()->put($group, ['jobs' => $jobs]);

        $this->write($groups->all());
    }

    /**
     * Upgrade an older flat store in place. Rewrites the file only when a job is
     * found in the old shape, and returns how many jobs were upgraded. Safe to
     * call repeatedly; it is a no-op once everything is on the current shape.
     */
    public function migrate(): int
    {
        if (! File::exists($this->path)) {
            return 0;
        }

        $decoded = json_decode(File::get($this->path), true);

        if (! is_array($decoded)) {
            return 0;
        }

        $outdated = 0;

        foreach ($decoded as $group) {
            foreach (data_get($group, 'jobs', []) as $job) {
                if (is_array($job) && ! array_key_exists('config', $job)) {
                    $outdated++;
                }
            }
        }

        if ($outdated > 0) {
            $this->write($this->all()->all());
        }

        return $outdated;
    }

    /**
     * @param  array<string, mixed>  $groups
     */
    protected function write(array $groups): void
    {
        File::ensureDirectoryExists(dirname($this->path));

        File::put(
            $this->path,
            json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    /**
     * @param  mixed  $group
     * @return array{jobs: array<int, array<string, mixed>>}
     */
    protected function normalizeGroup($group): array
    {
        $jobs = array_map(
            fn ($job) => is_array($job) ? $this->normalizeJob($job) : $job,
            data_get($group, 'jobs', []),
        );

        return array_merge(is_array($group) ? $group : [], ['jobs' => $jobs]);
    }

    /**
     * Move a job from the old flat shape (type fields alongside name/type/after)
     * to the current nested shape (fields under a `config` block). Already
     * nested jobs are returned untouched, so no data is lost or duplicated.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    protected function normalizeJob(array $job): array
    {
        if (array_key_exists('config', $job)) {
            return $job;
        }

        $normalized = [
            'name' => $job['name'] ?? '',
            'type' => $job['type'] ?? '',
            'config' => Arr::except($job, self::RESERVED),
        ];

        if (array_key_exists('after', $job)) {
            $normalized['after'] = $job['after'];
        }

        return $normalized;
    }
}
