<?php

namespace Rouxtaccess\Sync\Types;

use Rouxtaccess\Sync\Concerns\StreamsProcessProgress;
use Rouxtaccess\Sync\Contracts\ProgressReporter;
use Rouxtaccess\Sync\Contracts\SyncType;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\SyncResult;

class FilesOverSshSyncType implements SyncType
{
    use StreamsProcessProgress;

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

    public function run(array $job, bool $interactive, ProgressReporter $progress): SyncResult
    {
        $config = $job['config'] ?? [];
        $source = "{$config['ssh']}:".rtrim($config['remote_path'], '/').'/';
        $destination = rtrim($config['local_path'], '/').'/';

        $command = ['rsync', '-az', '--info=progress2'];

        if (data_get($config, 'delete')) {
            $command[] = '--delete';
        }

        $command = [...$command, '-e', 'ssh', $source, $destination];

        $progress->start("Syncing {$source} → {$destination}", 100);
        $reached = 0;

        $result = $this->streamProcess($command, function (string $stream, string $line) use ($progress, &$reached): void {
            if (preg_match('/(\d+)%/', $line, $matches) !== 1) {
                return;
            }

            $percent = (int) $matches[1];

            if ($percent > $reached) {
                $progress->advance($percent - $reached);
                $reached = $percent;
            }
        });

        if ($result->failed()) {
            $progress->finish();

            return SyncResult::failure(trim($result->errorOutput()) ?: 'rsync failed.');
        }

        $progress->finish("Synced {$source} → {$destination}.");

        return SyncResult::success("Synced {$source} → {$destination}.");
    }
}
