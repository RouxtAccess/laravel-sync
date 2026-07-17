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

## Extending

Everything is a class registered in `config/sync.php`. Never edit the package; register a class. The registry resolves it from the container, so constructor injection works.

| Add | Implement | Register under |
| --- | --- | --- |
| Sync type | `Rouxtaccess\Sync\Contracts\SyncType` | `types` |
| Database engine | `Rouxtaccess\Sync\Contracts\DatabaseDriver` | `database_drivers` |
| After-hook | `Rouxtaccess\Sync\Contracts\AfterHook` | `after_hooks` |
| Anonymizer | invokable class or SQL string | `anonymizers` |

### Sync type

`key()`, `label()`, `fields(): Field[]`, `summary(array $job): array`, `run(array $job, bool $interactive): SyncResult`. Both `run` and `summary` receive the whole job (`name`, `type`, `config`, `after`); the type's own field values live in `$job['config']`.

```php
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

    public function run(array $job, bool $interactive): SyncResult
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
- Reuse `Concerns\InteractsWithDatabaseDriver` and `Concerns\InteractsWithAfterHooks` for database types.

### Database engine

Implement `Contracts\DatabaseDriver`. Return `0` from `defaultPort()` for a portless (file based) engine so `db-over-ssh` rejects it. Read local settings from `config('database.connections.*')`. Pass passwords via env (`MYSQL_PWD`, `PGPASSWORD`), never argv, and `escapeshellarg` every interpolated value.

### After-hook

Implement `Contracts\AfterHook`: `appliesToJob()` gates the offer, `prompt()` returns true to defer, `handle()` runs at the end. Hooks run in `after_hooks` order. To query the imported database, use `Concerns\ConnectsToImportedDatabase::onImportedDatabase()`.

### Anonymizer

A raw SQL string or an invokable class called with the connection name. `Anonymizers\AnonymizeUserEmails` is a portable template that scrubs a table row by row and no-ops on a missing table or column.

## Testing an extension

- Command construction: `Process::fake()` then `Process::assertRan(...)`. Simulate failure with `Process::fake(['*' => Process::result(errorOutput: '...', exitCode: 1)])`.
- Database code: run against the in-memory sqlite connection; build a table with the schema builder and assert on the rows.
- Sync type: call `run($config, false)` directly and assert the returned `SyncResult`.

## Rules

- IMPORTANT: `sync-jobs.json` holds plaintext secrets. Never commit or print it. `rouxt:sync-install` gitignores it.
- IMPORTANT: never add a code path that writes to a production or upstream source.
- If you lack the SSH keys or AWS credentials to run a real sync, do not fake its output. Ask the user to run it.
