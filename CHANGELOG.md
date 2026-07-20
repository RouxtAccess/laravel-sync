# Changelog

All notable changes to `laravel-sync` will be documented in this file. The project follows semantic versioning.

## 3.0.0 - 2026-07-20

Database syncs now pull a dump to a local file before importing, and every sync type reports live progress. Both public interfaces changed, so this is a major release.

* Cached dumps: database syncs write the fetched dump to a gitignored `sync-dumps/` directory, then import from there, so a developer can pull once and re-import repeatedly (for testing) without hitting production again. Configured under `sync.dumps` (`path`, plus `keep` for how many dumps per job to retain).
* Interactive reuse: when a recent dump for a job exists, the command offers to reuse it instead of pulling fresh.
* Live progress: a `ProgressReporter` drives a per-table (database), per-file (rsync), or per-object (S3) bar on a TTY, and plain throttled lines under `--yes` or piped output, replacing the opaque spinner. rsync now passes `--info=progress2`, and dumps run verbosely so progress can advance per table.
* **Breaking:** `SyncType::run()` takes a third argument, a `ProgressReporter`. Custom sync types must update their signature.
* **Breaking:** `DatabaseDriver` gained `countTablesCommand()`, `dumpProgressPattern()`, `dataMarker()`, and `isDumpNoise()`, and `dumpCommand()` takes a `$verbose` flag. Custom drivers must implement these.
* The local target database is resolved before any dump is fetched, so a run that will be turned away (the target already exists) no longer pulls a production dump first.

## 2.0.0 - 2026-07-17

* **Breaking:** dropped support for the end-of-life Laravel 10 and 11. The package now targets Laravel 12 and 13.
* Removed the unused `pest-plugin-laravel` dependency and bumped CI actions.

## 1.0.0 - 2026-07-17

Initial release.

* Interactive `rouxt:sync` command driven by named groups of jobs.
* Sync types: `db-over-ssh`, `db-from-s3`, `files-over-ssh`, `s3-sync`.
* Database drivers: MySQL / MariaDB, PostgreSQL, SQLite.
* After-hooks: swap `.env` database, run migrations, anonymize.
* Environment guard with a `--force` override.
* `rouxt:sync-install` command that publishes config, drops a `sync-jobs.example.json` reference, and gitignores the store.
* Laravel Boost support for AI coding agents: terse always-on guidelines plus an on-demand `laravel-sync` skill.
* Automatic, lossless upgrade of older flat-format stores (type fields are moved under a `config` block) on read and when running `rouxt:sync`.
