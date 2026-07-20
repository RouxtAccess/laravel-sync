# Configuration

Two files matter: `config/sync.php` (committed, shared with your team) and `sync-jobs.json` (gitignored, personal, holds credentials).

## config/sync.php

- `store`: where the group file lives. Defaults to `base_path('sync-jobs.json')`, override with `SYNC_STORE_PATH`.
- `dumps.path`: where fetched database dumps are kept on disk. Defaults to `base_path('sync-dumps')`, override with `SYNC_DUMPS_PATH`.
- `dumps.keep`: how many dumps per job to retain. Defaults to `3`. Older dumps are pruned after each fetch.
- `guard.allowed_environments`: the environments where the command runs without `--force`. Defaults to `local`, `development`, `testing`.
- `types`, `database_drivers`, `after_hooks`: the registered classes. Append your own to add behaviour.
- `anonymizers`: the list run by the anonymize hook. Empty by default.

## Database dumps on disk

Database syncs pull a dump to a file first, then import it, so the same dump can be re-imported for testing without hitting production again. The dump directory is set by `dumps.path` (`SYNC_DUMPS_PATH`), and `dumps.keep` bounds how many dumps per job are kept before older ones are pruned.

```php
'dumps' => [
    'path' => env('SYNC_DUMPS_PATH', base_path('sync-dumps')),
    'keep' => 3,
],
```

These files hold plaintext production data, so `rouxt:sync-install` gitignores the directory. Keep it out of version control.

## Sync types

| Type | What it does |
| --- | --- |
| `db-over-ssh` | Dumps a remote database live through an SSH tunnel and imports it locally |
| `db-from-s3` | Restores the newest `.sql.gz` dump from an S3 folder |
| `files-over-ssh` | Copies a remote directory to a local path with rsync |
| `s3-sync` | Mirrors a bucket to another bucket or a local path |

## Database engines

`mysql` (MySQL and MariaDB), `pgsql` (PostgreSQL), and `sqlite`. Local connection settings are read from your app's `config/database.php`, so the tool uses the same local database your app does.

SQLite is a file, so it cannot be dumped through an SSH tunnel. Use a `files-over-ssh` job to copy the `.sqlite` file, or a `db-from-s3` job to restore a dump into a local file.

## After-hooks

Offered once a database job succeeds, and run at the end.

| Hook | What it does |
| --- | --- |
| `swap-env-database` | Points `.env` `DB_DATABASE` at the freshly imported database |
| `run-migrations` | Runs outstanding migrations on the imported database |
| `anonymize` | Runs your configured anonymizers on the imported database |

## Anonymizers

Anonymizers scrub sensitive data out of a freshly imported database, so a local copy never holds real customer details. List them in `config/sync.php`. Each entry is a raw SQL statement or the class name of an invokable action. Two portable examples ship with the package:

```php
use Rouxtaccess\Sync\Anonymizers\AnonymizeUserEmails;
use Rouxtaccess\Sync\Anonymizers\AnonymizeUserPhoneNumbers;

'anonymizers' => [
    AnonymizeUserEmails::class,        // users.email becomes user{id}@example.test
    AnonymizeUserPhoneNumbers::class,  // users.phone_number, msisdn, phone, mobile
    "UPDATE users SET password = '', remember_token = NULL",
],
```

The shipped examples only touch the `users` table, only change columns that exist, and keep each replacement unique. Copy `AnonymizeUserEmails` as a template for other tables.

## Upgrading an older store

Earlier versions kept a job's fields at the top level instead of under `config`. If you have such a file, you do not need to do anything. Reading the store normalizes it in memory, and running `rouxt:sync` rewrites it to the current shape in place, without losing any values.

## Example store file

`sync-jobs.json` is plain JSON. The wizard writes it, and `sync-jobs.example.json` (dropped by `rouxt:sync-install`) shows the shape. A job has `name`, `type`, a `config` block of the type's fields, and an optional `after` list:

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
