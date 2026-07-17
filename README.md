# Laravel Sync

[![Latest Version on Packagist](https://img.shields.io/packagist/v/rouxtaccess/laravel-sync.svg?style=flat-square)](https://packagist.org/packages/rouxtaccess/laravel-sync)
[![Tests](https://img.shields.io/github/actions/workflow/status/rouxtaccess/laravel-sync/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/rouxtaccess/laravel-sync/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/rouxtaccess/laravel-sync.svg?style=flat-square)](https://packagist.org/packages/rouxtaccess/laravel-sync)

Pull production databases, files and S3 buckets down to your local Laravel environment with one interactive command.

Sync is built around named **groups** of **jobs**. A job is one unit of work (a database, a folder of files, a bucket). You configure groups once, then run them with `php artisan rouxt:sync`. Everything is a small registered class, so you can add your own sync types, database engines and post-sync hooks without touching the package.

## Why this exists

Refreshing local data from production usually means a pile of one off bash scripts. This package turns that into a reusable, safe, interactive tool:

* Databases are dumped live over an SSH tunnel, so nothing extra needs to be installed on the production server.
* Imports are always downward. A sync only ever creates a new local database named `<prefix>_<date>`, it never writes back upstream.
* MySQL, MariaDB, PostgreSQL and SQLite are supported out of the box.
* Files come down over rsync, and S3 buckets sync with the AWS CLI.
* A hard environment guard refuses to run anywhere except your allowed environments.

## Requirements

* PHP 8.2, 8.3, 8.4 or 8.5
* Laravel 12 or 13
* The relevant client binaries on your machine for the jobs you run: `ssh`, `rsync`, `mysqldump` / `mysql`, `pg_dump` / `psql`, `sqlite3`, and the `aws` CLI.

## Installation

Install the package with Composer:

```bash
composer require rouxtaccess/laravel-sync --dev
```

Then publish the config and seed an example store:

```bash
php artisan rouxt:sync-install
```

This publishes `config/sync.php`, writes a `sync-jobs.example.json` reference file next to the store, and adds the real store (`sync-jobs.json`) to your `.gitignore`. The store holds plaintext credentials, so it must never be committed.

## Quick start

Run the command and follow the prompts to build your first group:

```bash
php artisan rouxt:sync
```

You will pick a sync type, answer a few questions (SSH target, database name, and so on), and optionally choose which after-hooks to offer. The group is saved to `sync-jobs.json`. Next time, run a named group directly:

```bash
php artisan rouxt:sync production
```

Add `--yes` to run every job in a group without prompting (useful in scripts). Add `--force` to run in an environment that is not on the allow list.

## Sync types

| Key | What it does | Transfer |
| --- | --- | --- |
| `db-over-ssh` | Dumps a remote database live and imports it locally | SSH tunnel, then `mysqldump` / `pg_dump` piped into the local client |
| `db-from-s3` | Restores the newest `.sql.gz` dump from an S3 folder | `aws s3 cp`, `gunzip`, then the local client |
| `files-over-ssh` | Copies a remote directory to a local path | `rsync -az` over SSH |
| `s3-sync` | Mirrors a bucket to another bucket or a local path | `aws s3 sync` |

The two database types import into a fresh local database named `<prefix>_<date>`. If that name already exists, an interactive run offers to abort, replace, or import under a different name. A non-interactive run (`--yes`) leaves it untouched.

## Database drivers

`mysql` (MySQL and MariaDB), `pgsql` (PostgreSQL) and `sqlite` are registered by default. Local client settings are read from your app's `config/database.connections.*`, so the tool talks to the same local database your app does.

SQLite is file based and has no network port, so it cannot be tunnelled. A `db-over-ssh` job rejects it with a hint to use a `files-over-ssh` job to copy the database file instead. SQLite works with `db-from-s3` (a dump is restored into a local `.sqlite` file).

## After-hooks

Once a database job succeeds, hooks can run. They are offered up front (in the same step as conflict handling) and executed at the end.

| Key | What it does |
| --- | --- |
| `swap-env-database` | Points `.env` `DB_DATABASE` at the freshly imported database |
| `run-migrations` | Runs outstanding migrations on the imported database |
| `anonymize` | Runs your configured anonymizers on the imported database |

Anonymizers are defined in `config/sync.php` under `anonymizers`. Anonymization is opt-in, so the list ships empty and the hook is only offered when it has entries. Each entry is either a raw SQL statement or the class name of an invokable action.

The package ships two ready-to-use, driver-portable examples (they scrub the `users` table one row at a time, so replacements stay unique, and they no-op when a table or column is absent):

```php
use Rouxtaccess\Sync\Anonymizers\AnonymizeUserEmails;
use Rouxtaccess\Sync\Anonymizers\AnonymizeUserPhoneNumbers;

'anonymizers' => [
    AnonymizeUserEmails::class,        // users.email -> user{id}@example.test
    AnonymizeUserPhoneNumbers::class,  // users.phone_number, msisdn, phone, mobile, ...
    "UPDATE users SET password = '', remember_token = NULL",
    App\Sync\Anonymizers\ScrubPaymentTokens::class,
],
```

An invokable action receives the connection name. Copy `AnonymizeUserEmails` as a template for other tables, or write your own:

```php
class ScrubPaymentTokens
{
    public function __invoke(string $connection): void
    {
        DB::connection($connection)->table('payment_methods')->update(['token' => null]);
    }
}
```

## The store file

Groups live in `sync-jobs.json` (the path is configurable via `SYNC_STORE_PATH`). It is plain JSON, so any editor treats it as a normal data file. The wizard writes it for you, and `sync-jobs.example.json` shows the shape. A job has `name`, `type`, a `config` block of the type's own fields, and an optional `after` list of hook keys:

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
                    "db_host": "127.0.0.1",
                    "db_port": 3306,
                    "db_name": "forge",
                    "db_user": "forge",
                    "db_pass": "secret",
                    "target_prefix": "myapp"
                },
                "after": ["swap-env-database", "run-migrations"]
            }
        ]
    }
}
```

## Environment guard

By default the command only runs in the `local`, `development` and `testing` environments. Anywhere else it refuses unless you pass `--force`. Adjust the list in `config/sync.php`:

```php
'guard' => [
    'allowed_environments' => ['local', 'development', 'testing'],
],
```

## Extending

Every moving part is a class registered in `config/sync.php`. Append your own to the relevant array.

Write a custom sync type by implementing `Rouxtaccess\Sync\Contracts\SyncType`, a database engine by implementing `Rouxtaccess\Sync\Contracts\DatabaseDriver`, or a post-sync hook by implementing `Rouxtaccess\Sync\Contracts\AfterHook`. Register the class:

```php
'types' => [
    // ...defaults
    App\Sync\Types\RedisSyncType::class,
],
```

## Documentation

Deeper docs live in [`docs/`](docs):

* [`docs/human/`](docs/human) is plain-language documentation for developers and operators (running a sync, configuration, extending, security).
* [`docs/claude/`](docs/claude) is terse, code-focused steering for AI working on the package (architecture with diagrams, extending, testing).

## AI agents (Laravel Boost)

This package ships [Laravel Boost](https://laravel.com/docs/boost) support for coding agents. If your app uses Boost, running `php artisan boost:install` picks both of these up:

* **Guidelines** (`resources/boost/guidelines/core.blade.php`) fold into your `AGENTS.md` / `CLAUDE.md`. They are deliberately short (loaded on every turn) and cover the command, the store file, the guard, and the safety rules.
* A **`laravel-sync` skill** (`resources/boost/skills/laravel-sync/SKILL.md`) loads on demand when an agent is configuring, running, or extending the package. It carries the deeper material: the store shape, the extension contracts, and testing patterns.

Both also tell an agent that when it lacks the SSH keys or AWS credentials to run a sync, it should ask you to run it rather than fake it.

## Security

The store file holds connection details in plaintext, including passwords. `rouxt:sync-install` adds it to `.gitignore`. Keep it out of version control, and prefer SSH keys and AWS profiles over inline passwords where you can.

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see the [license file](LICENSE.md) for more information.
