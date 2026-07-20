<?php

namespace Rouxtaccess\Sync\Database\Drivers;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Contracts\DatabaseDriver;

class PostgresDriver implements DatabaseDriver
{
    public static function key(): string
    {
        return 'pgsql';
    }

    public static function label(): string
    {
        return 'PostgreSQL';
    }

    public function defaultPort(): int
    {
        return 5432;
    }

    public function createDatabase(string $database): ProcessResult
    {
        return $this->runPsql(['-d', 'postgres', '-c', "CREATE DATABASE \"{$database}\";"]);
    }

    public function dropDatabase(string $database): void
    {
        $this->runPsql(['-d', 'postgres', '-c', "DROP DATABASE IF EXISTS \"{$database}\";"]);
    }

    public function databaseExists(string $database): bool
    {
        $result = $this->runPsql(['-d', 'postgres', '-tAc', "SELECT 1 FROM pg_database WHERE datname = '{$database}';"]);

        return $result->successful() && trim($result->output()) === '1';
    }

    public function dumpCommand(array $remote, int $port, bool $verbose = false): string
    {
        return 'PGPASSWORD='.escapeshellarg((string) (data_get($remote, 'db_pass') ?? '')).' pg_dump '
            .($verbose ? '--verbose ' : '')
            .'--no-owner --no-privileges '
            .'-h 127.0.0.1 -p '.escapeshellarg((string) $port)
            .' -U '.escapeshellarg($remote['db_user']).' '
            .escapeshellarg($remote['db_name']);
    }

    public function countTablesCommand(array $remote, int $port): ?string
    {
        return 'PGPASSWORD='.escapeshellarg((string) (data_get($remote, 'db_pass') ?? '')).' psql '
            .'-tA -h 127.0.0.1 -p '.escapeshellarg((string) $port)
            .' -U '.escapeshellarg($remote['db_user'])
            .' -d '.escapeshellarg($remote['db_name'])
            .' -c '.escapeshellarg("SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE';");
    }

    public function dumpProgressPattern(): ?string
    {
        return 'dumping contents of table ';
    }

    public function dataMarker(): ?string
    {
        return '^-- Data for Name: .*Type: TABLE DATA';
    }

    public function isDumpNoise(string $line): bool
    {
        // pg_dump --verbose prefixes every line with `pg_dump: `; only its
        // errors and warnings are worth surfacing on a failed fetch.
        if (! str_starts_with($line, 'pg_dump:')) {
            return false;
        }

        return ! str_contains($line, 'error:') && ! str_contains($line, 'warning:');
    }

    public function importCommand(string $database): string
    {
        $connection = $this->localConnection();

        return 'PGPASSWORD='.escapeshellarg((string) ($connection['password'] ?? '')).' '
            .implode(' ', array_map('escapeshellarg', [...$this->psqlBase(), '-d', $database]));
    }

    public function sanitizePipe(): ?string
    {
        return null;
    }

    /**
     * @param  array<int, string>  $arguments
     */
    protected function runPsql(array $arguments): ProcessResult
    {
        return Process::env(['PGPASSWORD' => (string) ($this->localConnection()['password'] ?? '')])
            ->run([...$this->psqlBase(), ...$arguments]);
    }

    /**
     * @return array<int, string>
     */
    protected function psqlBase(): array
    {
        $connection = $this->localConnection();

        return [
            'psql',
            '-h', (string) ($connection['host'] ?? '127.0.0.1'),
            '-p', (string) ($connection['port'] ?? 5432),
            '-U', (string) ($connection['username'] ?? 'postgres'),
        ];
    }

    /**
     * The local connection to import into. Prefers the conventionally named
     * `pgsql` connection, then falls back to the first configured connection
     * using the pgsql driver, so apps that name theirs differently still work.
     *
     * @return array<string, mixed>
     */
    protected function localConnection(): array
    {
        $connections = config('database.connections', []);

        if (is_array($connections['pgsql'] ?? null)) {
            return $connections['pgsql'];
        }

        foreach ($connections as $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'pgsql') {
                return $connection;
            }
        }

        return [];
    }
}
