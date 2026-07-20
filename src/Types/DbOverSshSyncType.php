<?php

namespace Rouxtaccess\Sync\Types;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Concerns\FetchesAndLoadsDatabase;
use Rouxtaccess\Sync\Contracts\DatabaseDriver;
use Rouxtaccess\Sync\Contracts\ProgressReporter;
use Rouxtaccess\Sync\Contracts\SyncType;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\SyncResult;

use function Laravel\Prompts\note;

class DbOverSshSyncType implements SyncType
{
    use FetchesAndLoadsDatabase;

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

    public function run(array $job, bool $interactive, ProgressReporter $progress): SyncResult
    {
        $config = $job['config'] ?? [];
        $driver = $this->driver($config);

        if ($driver->defaultPort() < 1) {
            return SyncResult::failure($driver::label().' has no network port to tunnel to. Use a files-over-ssh job to copy the database file instead.');
        }

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
     * Open a tunnel, dump the remote database to a local file with per-table
     * progress, then close the tunnel. Returns the dump path, or a failure.
     *
     * @param  array<string, mixed>  $job
     */
    protected function fetchDump(DatabaseDriver $driver, array $job, ProgressReporter $progress): SyncResult|string
    {
        $config = $job['config'] ?? [];
        $port = $this->freeLocalPort();
        $controlPath = sys_get_temp_dir().'/sync-db-'.getmypid().'-'.$port.'.sock';

        if ($this->openTunnel($config, $port, $controlPath)->failed()) {
            return SyncResult::failure("Could not open an SSH tunnel to {$config['ssh']}.");
        }

        $dumpFile = $this->dumpStore()->pathFor($this->jobName($job), now()->format('Y_m_d_His'));
        $pattern = $this->toPcre($driver->dumpProgressPattern());

        $errorLines = [];

        try {
            $progress->start("Dumping {$config['db_name']}", $this->remoteTableCount($driver, $config, $port));

            $result = $this->streamProcess(['bash', '-c', $this->dumpPipeline($driver, $config, $port, $dumpFile)], function (string $stream, string $line) use ($progress, $pattern, $driver, &$errorLines): void {
                if ($pattern !== null && preg_match($pattern, $line) === 1) {
                    $progress->advance(1, $this->tableFromMarker($line));

                    return;
                }

                if ($stream === 'err' && ! $driver->isDumpNoise($line)) {
                    $errorLines[] = $line;
                }
            });
        } finally {
            $this->closeTunnel($config, $controlPath);
        }

        if ($result->failed()) {
            $progress->finish();
            @unlink($dumpFile);
            note(trim(implode(PHP_EOL, $errorLines)) ?: trim($result->errorOutput()) ?: 'No error output was captured.');

            return SyncResult::failure("Could not dump {$config['db_name']}.");
        }

        $progress->finish();
        $this->dumpStore()->prune($this->jobName($job));

        return $dumpFile;
    }

    /**
     * Dump the remote database (verbosely, for progress) and optionally sanitize,
     * writing SQL to a local file.
     *
     * @param  array<string, mixed>  $config
     */
    protected function dumpPipeline(DatabaseDriver $driver, array $config, int $port, string $dumpFile): string
    {
        $parts = array_filter([
            $driver->dumpCommand($config, $port, verbose: true),
            $driver->sanitizePipe(),
        ]);

        return 'set -o pipefail; '.implode(' | ', $parts).' > '.escapeshellarg($dumpFile);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function remoteTableCount(DatabaseDriver $driver, array $config, int $port): ?int
    {
        $command = $driver->countTablesCommand($config, $port);

        if ($command === null) {
            return null;
        }

        $result = Process::run(['bash', '-c', $command]);

        if ($result->failed()) {
            return null;
        }

        $count = (int) trim($result->output());

        return $count > 0 ? $count : null;
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
