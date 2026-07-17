# Laravel Sync

- Laravel Sync pulls production data (databases, files, S3) down to a local environment. It is a development-only tool; never run it in production.
- Run a sync with `php artisan rouxt:sync {group?}` (`--yes` skips prompts, `--force` bypasses the environment guard).
- Groups and jobs live in the gitignored `sync-jobs.json`. IMPORTANT: it holds plaintext secrets; never commit or print its contents.
- Extend it by registering a class in `config/sync.php` under `types`, `database_drivers`, `after_hooks`, or `anonymizers`. Never edit the package to change behaviour.
- IMPORTANT: a sync only ever creates a new local `<prefix>_<date>` database and copies downward. Never add or run anything that writes to the upstream source.
- If you lack the SSH keys or AWS credentials to run a sync, do not fake its output. Ask the user to run it; they have the access.
- IMPORTANT: activate the `laravel-sync` skill when configuring, running, or extending Laravel Sync.
