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

class DbOverSshSyncType implements SyncType
{
    use InteractsWithAfterHooks;
    use InteractsWithDatabaseDriver;

    public static function key(): string
    {
        return 'db-over-ssh';
    }

    public static function label(): string
    {
        return 'Database — dump over an SSH tunnel';
    }

    public function fields(): array
    {
        return [
            $this->driverField(),
            new Field('ssh', 'SSH target', placeholder: 'forge@1.2.3.4'),
            new Field('db_host', 'Remote DB host (as seen from the prod server)', default: '127.0.0.1'),
            new Field('db_port', 'Remote DB port', default: fn (array $answers): string => (string) $this->driver($answers)->defaultPort(), cast: fn ($value): int => (int) $value),
            new Field('db_name', 'Remote database name'),
            new Field('db_user', 'Remote DB user'),
            new Field('db_pass', 'Remote DB password', required: false, secret: true),
            new Field('target_prefix', 'Local target DB prefix', default: fn (array $answers): string => $answers['db_name'] ?? '', hint: 'The snapshot is imported as <prefix>_<date>.'),
        ];
    }

    public function summary(array $job): array
    {
        $config = $job['config'] ?? [];

        return [
            ['Type', self::label()],
            ['Engine', $this->driver($config)::label()],
            ['Source host', $config['ssh']],
            ['Source database', "{$config['db_name']} ({$config['db_host']}:{$config['db_port']})"],
            ['Target (local)', $this->targetDatabase($config)],
        ];
    }

    public function run(array $job, bool $interactive): SyncResult
    {
        $config = $job['config'] ?? [];
        $driver = $this->driver($config);

        if ($driver->defaultPort() < 1) {
            return SyncResult::failure($driver::label().' has no network port to tunnel to. Use a files-over-ssh job to copy the database file instead.');
        }

        $intended = $this->targetDatabase($config);
        $target = $this->resolveTarget($driver, $intended, $interactive);

        if ($target === null) {
            return SyncResult::failure("Local database {$intended} already exists; left as-is.");
        }

        $context = ['database' => $target];
        $hooks = $this->planAfterHooks($job, $context, $interactive);

        $port = $this->freeLocalPort();
        $controlPath = sys_get_temp_dir().'/sync-db-'.getmypid().'-'.$port.'.sock';

        if ($this->openTunnel($config, $port, $controlPath)->failed()) {
            return SyncResult::failure("Could not open an SSH tunnel to {$config['ssh']}.");
        }

        try {
            if ($driver->createDatabase($target)->failed()) {
                return SyncResult::failure("Could not create local database {$target}.");
            }

            $result = spin(
                message: "Dumping {$config['db_name']} and importing into {$target}…",
                callback: fn (): ProcessResult => Process::timeout(0)->run(['bash', '-c', $this->pipeline($driver, $config, $port, $target)]),
            );
        } finally {
            $this->closeTunnel($config, $controlPath);
        }

        if ($result->failed()) {
            $driver->dropDatabase($target);
            note(trim($result->errorOutput()) ?: 'No error output was captured.');

            return SyncResult::failure('Dump/import failed. The half-created database was dropped.');
        }

        $this->runAfterHooks($hooks, $job, $context);

        return SyncResult::success("Imported into {$target}.", ['database' => $target]);
    }

    /**
     * Dump through the tunnel, optionally sanitize, import locally.
     *
     * @param  array<string, mixed>  $config
     */
    protected function pipeline(DatabaseDriver $driver, array $config, int $port, string $target): string
    {
        $parts = array_filter([
            $driver->dumpCommand($config, $port),
            $driver->sanitizePipe(),
            $driver->importCommand($target),
        ]);

        return 'set -o pipefail; '.implode(' | ', $parts);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function openTunnel(array $config, int $port, string $controlPath): ProcessResult
    {
        return Process::timeout(30)->run([
            'ssh', '-f', '-N', '-M', '-S', $controlPath,
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'ConnectTimeout=10',
            '-C',
            '-L', "127.0.0.1:{$port}:{$config['db_host']}:{$config['db_port']}",
            $config['ssh'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function closeTunnel(array $config, string $controlPath): void
    {
        Process::run(['ssh', '-O', 'exit', '-S', $controlPath, $config['ssh']]);
    }

    protected function freeLocalPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
