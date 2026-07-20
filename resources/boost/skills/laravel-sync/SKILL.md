---
name: laravel-sync
description: Configure, run, and extend the rouxtaccess/laravel-sync package, which pulls production data (databases, files, S3) down to a local environment. Activate when the user runs `rouxt:sync`, edits `config/sync.php` or `sync-jobs.json`, defines sync groups or jobs, or writes a custom sync type, database driver, after-hook, or anonymizer.
license: MIT
metadata:
  author: John Roux
---

# Laravel Sync

## Overview

Laravel Sync refreshes a local environment with real production data. It is a development-only tool. A sync only ever creates a new local database and copies downward; it never writes to the upstream source. Work is organised into named groups of jobs, run with `php artisan rouxt:sync`.

## When to activate

- The user wants to pull, sync, or refresh production data locally.
- The user runs or asks about `php artisan rouxt:sync` or `php artisan rouxt:sync-install`.
- The user edits `config/sync.php` or the `sync-jobs.json` store, or defines a group or job.
- The user wants to add a custom sync type, database driver, after-hook, or anonymizer.

## Configuring and running

Groups live in the gitignored `sync-jobs.json` (plain JSON; `sync-jobs.example.json` shows the shape). A job has top-level `name`, `type`, a `config` block holding that type's fields, and an optional `after` allow-list of hook keys.

```json
{
    "production": {
        "jobs": [
            {
                "name": "db",
                "type": "db-over-ssh",
                "config": {
                    "driver": "mysql",
                    "ssh": "forge@1.2.3.4",
                    "db_name": "forge",
                    "db_user": "forge",
                    "db_pass": "secret",
                    "target_prefix": "myapp"
                },
                "after": ["swap-env-database", "run-migrations", "anonymize"]
            }
        ]
    }
}
```

- Run a group: `php artisan rouxt:sync {group?}`. `--yes` runs every job without prompting; `--force` bypasses the environment guard.
- Built-in sync types: `db-over-ssh`, `db-from-s3`, `files-over-ssh`, `s3-sync`.
- Built-in database engines: `mysql`, `pgsql`, `sqlite`. SQLite cannot tunnel; use `files-over-ssh` or `db-from-s3` for it.
- Built-in after-hooks: `swap-env-database`, `run-migrations`, `anonymize`.
- The environment guard in `config/sync.php` (`guard.allowed_environments`) blocks non-allowed environments unless `--force` is passed.
- DB syncs fetch a dump to the gitignored `sync-dumps/` dir (path `SYNC_DUMPS_PATH`, `dumps.keep` bounds retention), then import it. The dump holds plaintext production data (not anonymized), so never commit it. An interactive DB run offers to reuse a recent dump (skip production) or pull fresh; `--yes` always pulls fresh.
- Every job reports progress: a live bar on an interactive TTY, plain lines for `--yes` or piped output.

## Extending

Everything is a class registered in `config/sync.php`. Never edit the package; register a class. The registry resolves it from the container, so constructor injection works.

| Add | Implement | Register under |
| --- | --- | --- |
| Sync type | `Rouxtaccess\Sync\Contracts\SyncType` | `types` |
| Database engine | `Rouxtaccess\Sync\Contracts\DatabaseDriver` | `database_drivers` |
| After-hook | `Rouxtaccess\Sync\Contracts\AfterHook` | `after_hooks` |
| Anonymizer | invokable class or SQL string | `anonymizers` |

### Sync type

`key()`, `label()`, `fields(): Field[]`, `summary(array $job): array`, `run(array $job, bool $interactive, ProgressReporter $progress): SyncResult`. Both `run` and `summary` receive the whole job (`name`, `type`, `config`, `after`); the type's own field values live in `$job['config']`. The `$progress` third arg (a `Contracts\ProgressReporter`) is required; drive it to report progress, or ignore it (a `NullProgressReporter` is passed when the caller wants silence).

```php
use Rouxtaccess\Sync\Contracts\ProgressReporter;
use Rouxtaccess\Sync\Contracts\SyncType;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\SyncResult;

class RedisSyncType implements SyncType
{
    public static function key(): string { return 'redis-copy'; }
    public static function label(): string { return 'Redis, copy keys from a remote instance'; }

    public function fields(): array
    {
        return [new Field('ssh', 'SSH target', placeholder: 'forge@1.2.3.4')];
    }

    public function summary(array $job): array
    {
        $config = $job['config'] ?? [];

        return [['Type', self::label()], ['Source', $config['ssh']]];
    }

    public function run(array $job, bool $interactive, ProgressReporter $progress): SyncResult
    {
        $config = $job['config'] ?? [];
        // shell out with Illuminate\Support\Facades\Process, then:
        return SyncResult::success('Copied keys.');
    }
}
```

- `Field` props: `key, label, required, secret, boolean, options (value=>label), default (string|bool|Closure(answers)), placeholder, hint, cast (Closure(value))`. The keys become entries in the job's `config` block.
- `run` receives `$interactive = false` for `--yes` or no TTY; do not prompt then, pick safe defaults.
- Return `SyncResult::success($msg, $data)` or `SyncResult::failure($msg)`. For database types put `['database' => $target]` in `$data` for the hooks.
- Reuse `Concerns\FetchesAndLoadsDatabase` (which pulls in `InteractsWithDatabaseDriver`, `InteractsWithAfterHooks`, and `StreamsProcessProgress`) for database types.

### ProgressReporter

`Contracts\ProgressReporter` reports phase progress to the caller: `start(string $label, ?int $total = null)` (null total draws an indeterminate phase), `setTotal(int $total)` (upgrade to a known total mid-phase), `advance(int $step = 1, ?string $detail = null)` (the `$detail` is the table or file shown alongside the bar), `finish(?string $message = null)`. Three implementations in `src/Progress/`: `NullProgressReporter` (no-op, the default for tests or silence), `LineProgressReporter` (plain append-only lines for non-interactive runs), `PromptsProgressReporter` (a live `laravel/prompts` bar for interactive TTY runs). `RunSyncCommand::progressReporter()` picks between the last two.

### Fetch and load (database types)

Database syncs split into a fetch (pull a dump to a file) and a load (import that file), sharing `Concerns\FetchesAndLoadsDatabase`:

- `resolveLoadTarget($driver, $config, $interactive)` resolves the local target name and is called before any fetch, so a run that will be turned away (target exists, non-interactive or the user declines to replace) never touches production. Return a failure when it hands back null.
- The composing type provides its own `fetchDump()` (a `mysqldump`/`pg_dump` over the tunnel for `db-over-ssh`, or `aws s3 cp | gunzip` for `db-from-s3`). During fetch the bar is sized by the remote table count (`DatabaseDriver::countTablesCommand`) and advanced per table matched against `dumpProgressPattern()`.
- `chooseDump($job, $interactive)` returns a recent dump to reuse or null to fetch fresh (interactive only; `--yes` always fetches).
- `loadDump()` creates the target database, pre-scans the dump file for its `dataMarker()` count to size the import bar, then streams the file through an `awk` pipeline that tees each marker line to stderr so the import advances per table, running after-hooks and rolling back on failure.
- `Database\DumpStore` (`DumpStore::fromConfig()`) manages the gitignored dump directory: `pathFor($job, $timestamp)`, `latest($job)`, `all($job)`, and `prune($job)` (keeps the newest `dumps.keep`). Dumps are timestamped, so a reverse lexical sort is reverse chronological.

### Database engine

Implement `Contracts\DatabaseDriver`: `key()`, `label()`, `defaultPort()`, `createDatabase()`, `dropDatabase()`, `databaseExists()`, `dumpCommand(array $remote, int $port, bool $verbose = false)`, `countTablesCommand(array $remote, int $port): ?string`, `dumpProgressPattern(): ?string`, `dataMarker(): ?string`, `isDumpNoise(string $line): bool`, `importCommand(string $db)`, `sanitizePipe(): ?string`.

- Return `0` from `defaultPort()` for a portless (file based) engine so `db-over-ssh` rejects it. Read local settings from `config('database.connections.*')`. Pass passwords via env (`MYSQL_PWD`, `PGPASSWORD`), never argv, and `escapeshellarg` every interpolated value.
- `dumpCommand` with `$verbose = true` should also print a per-table line to stderr that `dumpProgressPattern()` (a POSIX ERE) matches, so the fetch can advance.
- `countTablesCommand` prints the remote table count to stdout to size the fetch bar; return null when it cannot be counted.
- `dataMarker` is a POSIX ERE matching the per-table data marker inside a dump file (for example mysqldump's `-- Dumping data for table`); it both sizes and advances the import bar. Return null when the engine's dumps carry no such marker.
- `isDumpNoise` returns true for a verbose-stderr line that is progress chatter rather than a real error, so a failed fetch reports only the error rather than the whole verbose log. Return false when the dump prints no verbose output.

### After-hook

Implement `Contracts\AfterHook`: `appliesToJob()` gates the offer, `prompt()` returns true to defer, `handle()` runs at the end. Hooks run in `after_hooks` order. To query the imported database, use `Concerns\ConnectsToImportedDatabase::onImportedDatabase()`.

### Anonymizer

A raw SQL string or an invokable class called with the connection name. `Anonymizers\AnonymizeUserEmails` is a portable template that scrubs a table row by row and no-ops on a missing table or column.

## Testing an extension

- Command construction: `Process::fake()` then inspect the recorded commands. Simulate failure with `Process::fake(['*' => Process::result(errorOutput: '...', exitCode: 1)])`.
- Database code: run against the in-memory sqlite connection; build a table with the schema builder and assert on the rows.
- Sync type: call `run($job, false, new NullProgressReporter)` directly (the third arg is required) and assert the returned `SyncResult`. For DB fetch/load types, point `sync.dumps.path` at a temp dir and assert the fetch writes a `.sql` dump file (`mysqldump --verbose ... > ...sql`) and the load streams it through the marker-teeing `awk ... /dev/stderr ... | mysql` pipeline.

## Rules

- IMPORTANT: `sync-jobs.json` holds plaintext secrets. Never commit or print it. `rouxt:sync-install` gitignores it.
- IMPORTANT: never add a code path that writes to a production or upstream source.
- If you lack the SSH keys or AWS credentials to run a real sync, do not fake its output. Ask the user to run it.
