# Extending (AI)

Everything is a class registered in `config/sync.php`. Never edit the package to add behaviour to a consuming app; write a class and register it. The registry resolves classes from the container, so constructor injection works.

| Add | Implement | Register under |
| --- | --- | --- |
| Sync type | `Rouxtaccess\Sync\Contracts\SyncType` | `types` |
| Database engine | `Rouxtaccess\Sync\Contracts\DatabaseDriver` | `database_drivers` |
| After-hook | `Rouxtaccess\Sync\Contracts\AfterHook` | `after_hooks` |
| Anonymizer | invokable class or SQL string | `anonymizers` |

Hard rule: a sync reads upstream and writes locally. Never add a path that writes to a production source.

## SyncType

`key()`, `label()`, `fields(): Field[]`, `summary(array $job): array`, `run(array $job, bool $interactive, ProgressReporter $progress): SyncResult`.

- `run` and `summary` receive the whole job (`name`, `type`, `config`, `after`). The type's field values live in `$job['config']`, so start with `$config = $job['config'] ?? [];`. Pass `$job` to the after-hook helpers (they need `type`/`after`), and `$config` to the driver helpers.
- `fields()` returns `Rouxtaccess\Sync\Field` objects; the command renders each as a prompt and stores the answers in the job's `config` block. Field props: `key, label, required, secret, boolean, options (value=>label), default (string|bool|Closure(answers)), placeholder, hint, cast (Closure(value))`.
- `run` receives `$interactive = false` for `--yes` or no TTY. Do not prompt then; pick safe defaults (abort on clash, no hooks, always fetch a fresh dump).
- `$progress` (a `Contracts\ProgressReporter`) is required. Drive it (`start($label, ?$total)`, `setTotal()`, `advance($step, ?$detail)`, `finish(?$message)`) or ignore it. The command passes a live bar (`Progress\PromptsProgressReporter`) on an interactive TTY, else `Progress\LineProgressReporter`; tests pass `Progress\NullProgressReporter`.
- Return `SyncResult::success($msg, $data)` or `SyncResult::failure($msg)`. For DB types put `['database' => $target]` in `$data` for the hooks.
- For a DB type, reuse `Concerns\FetchesAndLoadsDatabase`. In `run()`: call `resolveLoadTarget()` first and bail with a failure when it returns null (this happens before any fetch, so a run that will be turned away never hits production), then `chooseDump()` to maybe reuse a dump, then your own `fetchDump()` (pull a dump to a file) when none is reused, then `loadDump($driver, $job, $dumpFile, $target, $interactive, $progress)` to import it. The trait pulls in `InteractsWithDatabaseDriver` (`driver()`, `driverField()`, `targetDatabase()`, `resolveTarget()`), `InteractsWithAfterHooks` (`planAfterHooks()`, `runAfterHooks()`), and `StreamsProcessProgress`, and adds `dumpStore()`. Reference: `src/Types/DbOverSshSyncType.php`.

```php
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
        return [['Type', self::label()], ['Source', $job['config']['ssh']]];
    }

    public function run(array $job, bool $interactive, ProgressReporter $progress): SyncResult
    {
        $config = $job['config'] ?? [];
        $result = spin('Copying...', fn () => Process::timeout(0)->run([/* cmd using $config */]));

        return $result->failed()
            ? SyncResult::failure(trim($result->errorOutput()) ?: 'Failed.')
            : SyncResult::success('Copied.');
    }
}
```

## ProgressReporter

`Contracts\ProgressReporter`: `start(string $label, ?int $total = null)`, `setTotal(int $total)`, `advance(int $step = 1, ?string $detail = null)`, `finish(?string $message = null)`. A null total renders indeterminate until `setTotal()`; `$detail` is the table/file shown alongside the bar. Impls in `src/Progress/`: `NullProgressReporter` (no-op, tests/silence), `LineProgressReporter` (plain lines, non-interactive), `PromptsProgressReporter` (live bar, interactive TTY). To size a bar, run a counting command first (remote table count, S3 object count), then advance per unit.

## DatabaseDriver

`key()`, `label()`, `defaultPort()`, `createDatabase()`, `dropDatabase()`, `databaseExists()`, `dumpCommand(array $remote, int $port, bool $verbose = false)`, `countTablesCommand(array $remote, int $port): ?string`, `dumpProgressPattern(): ?string`, `dataMarker(): ?string`, `isDumpNoise(string $line): bool`, `importCommand(string $db)`, `sanitizePipe(): ?string`.

- Return `0` from `defaultPort()` for a portless (file based) engine; `db-over-ssh` rejects it.
- Local client settings from `config('database.connections.*')`.
- Pass passwords via env (`MYSQL_PWD`, `PGPASSWORD`), never argv. `escapeshellarg` every interpolated value.
- `dumpCommand` with `$verbose = true` also prints a per-table line to stderr that `dumpProgressPattern()` (a POSIX ERE) matches, advancing the fetch bar.
- `countTablesCommand` prints the remote table count to stdout (sizes the fetch bar); `null` when uncountable.
- `dataMarker` is a POSIX ERE matching the per-table data marker in a dump file (mysqldump's `-- Dumping data for table`); it sizes and advances the import bar. `null` when the dumps carry no marker.
- `isDumpNoise` returns true for a verbose-stderr line that is progress chatter rather than a real error, so a failed fetch shows only the error (mysqldump prefixes chatter with `-- `, pg_dump with `pg_dump:` sans `error:`/`warning:`). `false` for engines with no verbose output.
- `sanitizePipe()` is an optional stdin filter between dump and import, or `null`. Reference: `src/Database/Drivers/MysqlDriver.php`, `SqliteDriver.php`.

## AfterHook

`key()`, `label()`, `appliesToJob(array $job): bool`, `prompt(array $job, array $context, bool $interactive): bool`, `handle(array $job, array $context): string`.

- `appliesToJob` gates the offer, `prompt` returns true to defer to the end, `handle` runs and returns a status string.
- Hooks run in `after_hooks` order (`swap-env-database` before `run-migrations` so env points at the new DB first).
- To query the imported DB, use `Concerns\ConnectsToImportedDatabase::onImportedDatabase($job, $context, fn ($connection) => ...)`. See `RunMigrationsHook`, `AnonymizeDatabaseHook`.

## Anonymizer

Raw SQL string, or an invokable class `__invoke(string $connection): void`, listed under `anonymizers`. Portable template: `Anonymizers\AnonymizeUserEmails` (uses `Anonymizers\Concerns\AnonymizesTable`, which scrubs row by row and no-ops on a missing table/column).

## Replacing defaults

Registries are plain config arrays. Register a subclass in place of a default, or omit a type to remove it. The config is the whole menu.

## Testing an extension

- Command construction: `Process::fake()` then inspect the recorded commands. Simulate failure with `Process::fake(['*' => Process::result(errorOutput: '...', exitCode: 1)])`.
- DB code: run against the in-memory sqlite connection; build a table with the schema builder, assert rows.
- Sync type: call `run($job, false, new NullProgressReporter)` (progress arg required) and assert the `SyncResult`. For fetch/load DB types point `sync.dumps.path` at a temp dir.

See [testing.md](testing.md) for the full patterns.
