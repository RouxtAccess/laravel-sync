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
     * 127.0.0.1:$port (through an SSH tunnel), writing SQL to stdout. When
     * $verbose is true the command should also print a per-table line to stderr
     * so a fetch can report progress (matched by dumpProgressPattern()).
     *
     * @param  array<string, mixed>  $remote  remote connection details (db_name, db_user, db_pass)
     */
    public function dumpCommand(array $remote, int $port, bool $verbose = false): string;

    /**
     * A shell command that prints the number of tables in the remote database to
     * stdout, used to size a fetch progress bar. Return null for engines that
     * cannot be counted over a port (the fetch then runs indeterminate).
     *
     * @param  array<string, mixed>  $remote
     */
    public function countTablesCommand(array $remote, int $port): ?string;

    /**
     * A POSIX extended regular expression matching a per-table line in the dump
     * command's verbose stderr, used to advance a fetch progress bar. Return
     * null when the engine emits no such markers.
     */
    public function dumpProgressPattern(): ?string;

    /**
     * A POSIX extended regular expression matching the per-table data marker
     * inside a dump file (e.g. mysqldump's "-- Dumping data for table"). Used to
     * both size and advance an import progress bar. Return null when the engine's
     * dumps carry no such marker (the import then runs indeterminate).
     */
    public function dataMarker(): ?string;

    /**
     * Whether a line on the dump command's verbose stderr is informational noise
     * (the per-table chatter `--verbose` prints) rather than a real error. A
     * failed fetch uses this to report only the error, not the whole verbose log.
     * Return false for engines whose dump emits no verbose output.
     */
    public function isDumpNoise(string $line): bool;

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
