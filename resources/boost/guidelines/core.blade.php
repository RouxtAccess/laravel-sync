# Laravel Sync

- `php artisan rouxt:sync {group?}` pulls production data down to local. Development only; never write to the upstream source.
- Gitignored `sync-jobs.json` and `sync-dumps/` hold plaintext secrets and production data; never commit or print them.
- Extend via classes registered in `config/sync.php`, never edit the package.
- IMPORTANT: activate the `laravel-sync` skill for any Laravel Sync work.
