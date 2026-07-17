<?php

namespace Rouxtaccess\Sync\Contracts;

use Illuminate\Contracts\Process\ProcessResult;

interface DatabaseDriver
{
    public static function key(): string;

    public static function label(): string;

    /**
     * The default remote port for this engine (used as a form default). Return 0
     * for file based engines that cannot be reached over a network port; the
     * SSH tunnel sync type rejects those.
     */
    public function defaultPort(): int;

    /**
     * Create the target database on the local server.
     */
    public function createDatabase(string $database): ProcessResult;

    /**
     * Drop the target database on the local server.
     */
    public function dropDatabase(string $database): void;

    /**
     * Whether the target database already exists on the local server.
     */
    public function databaseExists(string $database): bool;

    /**
     * A shell command that dumps the remote database reachable at
     * 127.0.0.1:$port (through an SSH tunnel), writing SQL to stdout.
     *
     * @param  array<string, mixed>  $remote  remote connection details (db_name, db_user, db_pass)
     */
    public function dumpCommand(array $remote, int $port): string;

    /**
     * A shell command that reads SQL from stdin into the local $database.
     */
    public function importCommand(string $database): string;

    /**
     * An optional stdin filter applied between dump and import (e.g. stripping
     * MySQL DEFINER clauses). Return null when the engine needs none.
     */
    public function sanitizePipe(): ?string;
}
