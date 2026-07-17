<?php

namespace Rouxtaccess\Sync\Database\Drivers;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Contracts\DatabaseDriver;

class MysqlDriver implements DatabaseDriver
{
    public static function key(): string
    {
        return 'mysql';
    }

    public static function label(): string
    {
        return 'MySQL / MariaDB';
    }

    public function defaultPort(): int
    {
        return 3306;
    }

    public function createDatabase(string $database): ProcessResult
    {
        return $this->runClient(['-e', "CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"]);
    }

    public function dropDatabase(string $database): void
    {
        $this->runClient(['-e', "DROP DATABASE IF EXISTS `{$database}`;"]);
    }

    public function databaseExists(string $database): bool
    {
        $result = $this->runClient([
            '-N', '-B', '-e',
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$database}';",
        ]);

        return $result->successful() && filled(trim($result->output()));
    }

    public function dumpCommand(array $remote, int $port): string
    {
        return 'MYSQL_PWD='.escapeshellarg((string) (data_get($remote, 'db_pass') ?? '')).' mysqldump '
            .'--single-transaction --quick --no-tablespaces --set-gtid-purged=OFF --routines --triggers --events '
            .'-h 127.0.0.1 -P '.escapeshellarg((string) $port)
            .' -u '.escapeshellarg($remote['db_user']).' '
            .escapeshellarg($remote['db_name']);
    }

    public function importCommand(string $database): string
    {
        $connection = $this->localConnection();

        return 'MYSQL_PWD='.escapeshellarg((string) ($connection['password'] ?? '')).' '
            .implode(' ', array_map('escapeshellarg', [...$this->clientBase(), $database]));
    }

    public function sanitizePipe(): ?string
    {
        return 'sed -E \'s/DEFINER=`[^`]+`@`[^`]+`//g\'';
    }

    /**
     * @param  array<int, string>  $arguments
     */
    protected function runClient(array $arguments): ProcessResult
    {
        return Process::env(['MYSQL_PWD' => (string) ($this->localConnection()['password'] ?? '')])
            ->run([...$this->clientBase(), ...$arguments]);
    }

    /**
     * @return array<int, string>
     */
    protected function clientBase(): array
    {
        $connection = $this->localConnection();

        return [
            'mysql',
            '-h', (string) ($connection['host'] ?? '127.0.0.1'),
            '-P', (string) ($connection['port'] ?? 3306),
            '-u', (string) ($connection['username'] ?? 'root'),
        ];
    }

    /**
     * The local connection to import into. Prefers the conventionally named
     * `mysql` connection, then falls back to the first configured connection
     * using a mysql or mariadb driver, so apps that name theirs differently
     * still work.
     *
     * @return array<string, mixed>
     */
    protected function localConnection(): array
    {
        $connections = config('database.connections', []);

        if (is_array($connections['mysql'] ?? null)) {
            return $connections['mysql'];
        }

        foreach ($connections as $connection) {
            if (is_array($connection) && in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
                return $connection;
            }
        }

        return [];
    }
}
