<?php

namespace Rouxtaccess\Sync\Types;

use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Concerns\StreamsProcessProgress;
use Rouxtaccess\Sync\Contracts\ProgressReporter;
use Rouxtaccess\Sync\Contracts\SyncType;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\SyncResult;

class S3SyncType implements SyncType
{
    use StreamsProcessProgress;

    public static function key(): string
    {
        return 's3-sync';
    }

    public static function label(): string
    {
        return 'Files — aws s3 sync (S3 ↔ local, S3 ↔ S3)';
    }

    public function fields(): array
    {
        return [
            new Field('source', 'Source (s3://… or a local path)', placeholder: 's3://prod-bucket'),
            new Field('destination', 'Destination (s3://… or a local path)', placeholder: 's3://local-bucket'),
            new Field('aws_profile', 'AWS profile', required: false, placeholder: 'default'),
            new Field('delete', 'Delete files at the destination that no longer exist at the source?', required: false, boolean: true, default: false),
        ];
    }

    public function summary(array $job): array
    {
        $config = $job['config'] ?? [];

        return [
            ['Type', self::label()],
            ['Source', $config['source']],
            ['Destination', $config['destination']],
            ['Delete extraneous', data_get($config, 'delete') ? 'yes' : 'no'],
        ];
    }

    public function run(array $job, bool $interactive, ProgressReporter $progress): SyncResult
    {
        $config = $job['config'] ?? [];
        $command = ['aws', 's3', 'sync', $config['source'], $config['destination']];

        if (data_get($config, 'delete')) {
            $command[] = '--delete';
        }

        if (filled(data_get($config, 'aws_profile'))) {
            $command = [...$command, '--profile', $config['aws_profile']];
        }

        $progress->start("Syncing {$config['source']} → {$config['destination']}", $this->countObjects($config));

        $result = $this->streamProcess($command, function (string $stream, string $line) use ($progress): void {
            if (preg_match('/^(copy|upload|download): \S+ to (\S+)/i', $line, $matches) === 1) {
                $progress->advance(1, basename($matches[2]));
            }
        });

        if ($result->failed()) {
            $progress->finish();

            return SyncResult::failure(trim($result->errorOutput()) ?: 'aws s3 sync failed.');
        }

        $progress->finish("Synced {$config['source']} → {$config['destination']}.");

        return SyncResult::success("Synced {$config['source']} → {$config['destination']}.");
    }

    /**
     * Count the objects under an S3 source to size the progress bar. Returns null
     * for a local source (nothing to list) or when the listing fails.
     *
     * @param  array<string, mixed>  $config
     */
    protected function countObjects(array $config): ?int
    {
        $source = (string) $config['source'];

        if (! str_starts_with($source, 's3://')) {
            return null;
        }

        $profile = filled(data_get($config, 'aws_profile')) ? ' --profile '.escapeshellarg($config['aws_profile']) : '';
        $command = 'aws s3 ls '.escapeshellarg(rtrim($source, '/').'/').' --recursive'.$profile.' | wc -l';

        $result = Process::run(['bash', '-c', $command]);

        if ($result->failed()) {
            return null;
        }

        $count = (int) trim($result->output());

        return $count > 0 ? $count : null;
    }
}
