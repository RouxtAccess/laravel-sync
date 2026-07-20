<?php

namespace Rouxtaccess\Sync\Database\Drivers;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Contracts\DatabaseDriver;
use RuntimeException;

class SqliteDriver implements DatabaseDriver
{
    public static function key(): string
    {
        return 'sqlite';
    }

    public static function label(): string
    {
        return 'SQLite';
    }

    /**
     * SQLite is file based and has no network port. Returning 0 tells the
     * SSH tunnel sync type to reject it.
     */
    public function defaultPort(): int
    {
        return 0;
    }

    /**
     * Resolve a target name to a database file path. An explicit path (absolute
     * or containing a directory separator or a known extension) is used as-is;
     * a bare name lands in the app's database directory as <name>.sqlite.
     */
    public static function path(string $database): string
    {
        if (str_contains($database, '/') || str_ends_with($database, '.sqlite') || str_ends_with($database, '.db')) {
            return $database;
        }

        return database_path($database.'.sqlite');
    }

    public function createDatabase(string $database): ProcessResult
    {
        $path = self::path($database);
        File::ensureDirectoryExists(dirname($path));

        return Process::run(['touch', $path]);
    }

    public function dropDatabase(string $database): void
    {
        Process::run(['rm', '-f', self::path($database)]);
    }

    public function databaseExists(string $database): bool
    {
        return File::exists(self::path($database));
    }

    public function dumpCommand(array $remote, int $port, bool $verbose = false): string
    {
        throw new RuntimeException('SQLite cannot be dumped over an SSH tunnel. Use a files-over-ssh job to copy the database file instead.');
    }

    public function countTablesCommand(array $remote, int $port): ?string
    {
        return null;
    }

    public function dumpProgressPattern(): ?string
    {
        return null;
    }

    public function dataMarker(): ?string
    {
        return null;
    }

    public function isDumpNoise(string $line): bool
    {
        return false;
    }

    public function importCommand(string $database): string
    {
        return 'sqlite3 '.escapeshellarg(self::path($database));
    }

    public function sanitizePipe(): ?string
    {
        return null;
    }
}
