<?php

namespace Rouxtaccess\Sync\Database;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Manages the local, gitignored directory of fetched database dumps. A dump is a
 * plaintext copy of production data, so callers keep it off version control and
 * prune old ones. Splitting the fetch from the load lets a developer pull once
 * and import the same dump repeatedly (for testing) without hitting production
 * again.
 */
class DumpStore
{
    public function __construct(
        protected string $directory,
        protected int $keep,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            directory: (string) config('sync.dumps.path', base_path('sync-dumps')),
            keep: (int) config('sync.dumps.keep', 3),
        );
    }

    /**
     * A fresh, timestamped path to dump a job into. The directory is created on
     * demand so the caller can write straight away.
     */
    public function pathFor(string $job, string $timestamp): string
    {
        File::ensureDirectoryExists($this->directory);

        return $this->directory.'/'.Str::slug($job).'-'.$timestamp.'.sql';
    }

    /**
     * A glob whose timestamp segment (Y_m_d_His) is spelled out in digit classes,
     * so a job named "db" does not also match "db-analytics-…". A trailing `*`
     * would let one job's slug swallow another whose slug starts the same way.
     */
    protected function pattern(string $job): string
    {
        $stamp = '[0-9][0-9][0-9][0-9]_[0-9][0-9]_[0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9]';

        return $this->directory.'/'.Str::slug($job).'-'.$stamp.'.sql';
    }

    /**
     * The most recent existing dump for a job, or null when none has been kept.
     */
    public function latest(string $job): ?string
    {
        $dumps = $this->all($job);

        return $dumps[0] ?? null;
    }

    /**
     * Every kept dump for a job, newest first. Names are timestamped, so a
     * reverse lexical sort is also reverse chronological.
     *
     * @return array<int, string>
     */
    public function all(string $job): array
    {
        if (! File::isDirectory($this->directory)) {
            return [];
        }

        $dumps = File::glob($this->pattern($job)) ?: [];

        rsort($dumps);

        return $dumps;
    }

    /**
     * Drop all but the newest `keep` dumps for a job.
     */
    public function prune(string $job): void
    {
        foreach (array_slice($this->all($job), $this->keep) as $stale) {
            File::delete($stale);
        }
    }
}
