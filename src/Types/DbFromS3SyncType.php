<?php

namespace Rouxtaccess\Sync\Types;

use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Concerns\FetchesAndLoadsDatabase;
use Rouxtaccess\Sync\Contracts\DatabaseDriver;
use Rouxtaccess\Sync\Contracts\ProgressReporter;
use Rouxtaccess\Sync\Contracts\SyncType;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\SyncResult;

use function Laravel\Prompts\note;

class DbFromS3SyncType implements SyncType
{
    use FetchesAndLoadsDatabase;

    public static function key(): string
    {
        return 'db-from-s3';
    }

    public static function label(): string
    {
        return 'Database — restore the latest dump from S3';
    }

    public function fields(): array
    {
        return [
            $this->driverField(),
            new Field('s3_path', 'S3 folder of dumps', placeholder: 's3://my-bucket/mysql/'),
            new Field('aws_profile', 'AWS profile', required: false, placeholder: 'default'),
            new Field('target_prefix', 'Local target DB prefix', hint: 'The snapshot is imported as <prefix>_<date>.'),
        ];
    }

    public function summary(array $job): array
    {
        $config = $job['config'] ?? [];

        return [
            ['Type', self::label()],
            ['Engine', $this->driver($config)::label()],
            ['S3 source', rtrim($config['s3_path'], '/').'/'],
            ['Target (local)', $this->targetDatabase($config)],
        ];
    }

    public function run(array $job, bool $interactive, ProgressReporter $progress): SyncResult
    {
        $config = $job['config'] ?? [];
        $driver = $this->driver($config);

        $dumpFile = $this->chooseDump($this->jobName($job), $interactive);

        $intended = $this->targetDatabase($config);
        $target = $this->resolveLoadTarget($driver, $config, $interactive);

        if ($target === null) {
            return SyncResult::failure("Local database {$intended} already exists; left as-is.");
        }

        if ($dumpFile === null) {
            $fetched = $this->fetchDump($driver, $job, $progress);

            if ($fetched instanceof SyncResult) {
                return $fetched;
            }

            $dumpFile = $fetched;
        }

        return $this->loadDump($driver, $job, $dumpFile, $target, $interactive, $progress);
    }

    /**
     * Download and decompress the latest S3 dump to a local file.
     *
     * @param  array<string, mixed>  $job
     */
    protected function fetchDump(DatabaseDriver $driver, array $job, ProgressReporter $progress): SyncResult|string
    {
        $config = $job['config'] ?? [];
        $key = $this->latestDumpKey($config);

        if ($key === '') {
            return SyncResult::failure("No .sql.gz dumps found in {$config['s3_path']}.");
        }

        $uri = rtrim($config['s3_path'], '/').'/'.$key;
        $dumpFile = $this->dumpStore()->pathFor($this->jobName($job), now()->format('Y_m_d_His'));

        $progress->start("Downloading {$key}");

        $result = $this->streamProcess(
            ['bash', '-c', $this->downloadPipeline($driver, $config, $uri, $dumpFile)],
            fn (string $stream, string $line) => null,
        );

        if ($result->failed()) {
            $progress->finish();
            @unlink($dumpFile);
            note(trim($result->errorOutput()) ?: 'No error output was captured.');

            return SyncResult::failure("Could not download {$key}.");
        }

        $progress->finish("Downloaded {$key}.");
        $this->dumpStore()->prune($this->jobName($job));

        return $dumpFile;
    }

    /**
     * Filenames are timestamped, so a lexical sort is also chronological.
     *
     * @param  array<string, mixed>  $config
     */
    protected function latestDumpKey(array $config): string
    {
        $command = 'aws s3 ls '.escapeshellarg(rtrim($config['s3_path'], '/').'/').$this->profileFlag($config)
            ." | grep '\\.sql\\.gz$' | sort | tail -1 | awk '{print \$4}'";

        $result = Process::run(['bash', '-c', $command]);

        return $result->successful() ? trim($result->output()) : '';
    }

    /**
     * Stream the S3 object down, decompress it, optionally sanitize, and write
     * the SQL to a local file for the import to read.
     *
     * @param  array<string, mixed>  $config
     */
    protected function downloadPipeline(DatabaseDriver $driver, array $config, string $uri, string $dumpFile): string
    {
        $parts = array_filter([
            'aws s3 cp '.escapeshellarg($uri).$this->profileFlag($config).' -',
            'gunzip -c',
            $driver->sanitizePipe(),
        ]);

        return 'set -o pipefail; '.implode(' | ', $parts).' > '.escapeshellarg($dumpFile);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function profileFlag(array $config): string
    {
        return filled(data_get($config, 'aws_profile')) ? ' --profile '.escapeshellarg($config['aws_profile']) : '';
    }
}
