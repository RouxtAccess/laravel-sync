<?php

namespace Rouxtaccess\Sync\Types;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Contracts\SyncType;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\SyncResult;

use function Laravel\Prompts\spin;

class FilesOverSshSyncType implements SyncType
{
    public static function key(): string
    {
        return 'files-over-ssh';
    }

    public static function label(): string
    {
        return 'Files — rsync from an SSH server';
    }

    public function fields(): array
    {
        return [
            new Field('ssh', 'SSH target', placeholder: 'forge@1.2.3.4'),
            new Field('remote_path', 'Remote path', placeholder: '/home/forge/site/storage/app/public'),
            new Field('local_path', 'Local path', placeholder: 'storage/app/public'),
            new Field('delete', 'Delete files at the destination that no longer exist at the source?', required: false, boolean: true, default: false),
        ];
    }

    public function summary(array $job): array
    {
        $config = $job['config'] ?? [];

        return [
            ['Type', self::label()],
            ['Source', "{$config['ssh']}:{$config['remote_path']}"],
            ['Destination', $config['local_path']],
            ['Delete extraneous', data_get($config, 'delete') ? 'yes' : 'no'],
        ];
    }

    public function run(array $job, bool $interactive): SyncResult
    {
        $config = $job['config'] ?? [];
        $source = "{$config['ssh']}:".rtrim($config['remote_path'], '/').'/';
        $destination = rtrim($config['local_path'], '/').'/';

        $command = ['rsync', '-az'];

        if (data_get($config, 'delete')) {
            $command[] = '--delete';
        }

        $command = [...$command, '-e', 'ssh', $source, $destination];

        $result = spin(
            message: "Syncing {$source} → {$destination}…",
            callback: fn (): ProcessResult => Process::timeout(0)->run($command),
        );

        return $result->failed()
            ? SyncResult::failure(trim($result->errorOutput()) ?: 'rsync failed.')
            : SyncResult::success("Synced {$source} → {$destination}.");
    }
}
