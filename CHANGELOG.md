# Changelog

All notable changes to `laravel-sync` will be documented in this file.

## 1.0.0 - Unreleased

Initial release.

* Interactive `rouxt:sync` command driven by named groups of jobs.
* Sync types: `db-over-ssh`, `db-from-s3`, `files-over-ssh`, `s3-sync`.
* Database drivers: MySQL / MariaDB, PostgreSQL, SQLite.
* After-hooks: swap `.env` database, run migrations, anonymize.
* Environment guard with a `--force` override.
* `rouxt:sync-install` command that publishes config, drops a `sync-jobs.example.json` reference, and gitignores the store.
* Laravel Boost support for AI coding agents: terse always-on guidelines plus an on-demand `laravel-sync` skill.
* Automatic, lossless upgrade of older flat-format stores (type fields are moved under a `config` block) on read and when running `rouxt:sync`.
