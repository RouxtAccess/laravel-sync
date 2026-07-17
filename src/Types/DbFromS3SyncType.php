<?php

namespace Rouxtaccess\Sync\Types;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Concerns\InteractsWithAfterHooks;
use Rouxtaccess\Sync\Concerns\InteractsWithDatabaseDriver;
use Rouxtaccess\Sync\Contracts\DatabaseDriver;
use Rouxtaccess\Sync\Contracts\SyncType;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\SyncResult;

use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;

class DbFromS3SyncType implements SyncType
{
    use InteractsWithAfterHooks;
    use InteractsWithDatabaseDriver;

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

    public function run(array $job, bool $interactive): SyncResult
    {
        $config = $job['config'] ?? [];
        $driver = $this->driver($config);
        $intended = $this->targetDatabase($config);

        $target = $this->resolveTarget($driver, $intended, $interactive);

        if ($target === null) {
            return SyncResult::failure("Local database {$intended} already exists; left as-is.");
        }

        $key = $this->latestDumpKey($config);

        if ($key === '') {
            return SyncResult::failure("No .sql.gz dumps found in {$config['s3_path']}.");
        }

        $context = ['database' => $target];
        $hooks = $this->planAfterHooks($job, $context, $interactive);

        if ($driver->createDatabase($target)->failed()) {
            return SyncResult::failure("Could not create local database {$target}.");
        }

        $uri = rtrim($config['s3_path'], '/').'/'.$key;

        $result = spin(
            message: "Restoring {$key} into {$target}…",
            callback: fn (): ProcessResult => Process::timeout(0)->run(['bash', '-c', $this->pipeline($driver, $config, $uri, $target)]),
        );

        if ($result->failed()) {
            $driver->dropDatabase($target);
            note(trim($result->errorOutput()) ?: 'No error output was captured.');

            return SyncResult::failure('Restore failed. The half-created database was dropped.');
        }

        $this->runAfterHooks($hooks, $job, $context);

        return SyncResult::success("Restored {$key} into {$target}.", ['database' => $target]);
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
     * @param  array<string, mixed>  $config
     */
    protected function pipeline(DatabaseDriver $driver, array $config, string $uri, string $target): string
    {
        $parts = array_filter([
            'aws s3 cp '.escapeshellarg($uri).$this->profileFlag($config).' -',
            'gunzip -c',
            $driver->sanitizePipe(),
            $driver->importCommand($target),
        ]);

        return 'set -o pipefail; '.implode(' | ', $parts);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function profileFlag(array $config): string
    {
        return filled(data_get($config, 'aws_profile')) ? ' --profile '.escapeshellarg($config['aws_profile']) : '';
    }
}
