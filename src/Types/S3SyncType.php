<?php

namespace Rouxtaccess\Sync\Types;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Contracts\SyncType;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\SyncResult;

use function Laravel\Prompts\spin;

class S3SyncType implements SyncType
{
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

    public function run(array $job, bool $interactive): SyncResult
    {
        $config = $job['config'] ?? [];
        $command = ['aws', 's3', 'sync', $config['source'], $config['destination']];

        if (data_get($config, 'delete')) {
            $command[] = '--delete';
        }

        if (filled(data_get($config, 'aws_profile'))) {
            $command = [...$command, '--profile', $config['aws_profile']];
        }

        $result = spin(
            message: "Syncing {$config['source']} → {$config['destination']}…",
            callback: fn (): ProcessResult => Process::timeout(0)->run($command),
        );

        return $result->failed()
            ? SyncResult::failure(trim($result->errorOutput()) ?: 'aws s3 sync failed.')
            : SyncResult::success("Synced {$config['source']} → {$config['destination']}.");
    }
}
