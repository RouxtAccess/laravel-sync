# AI steering docs

Terse, code-focused steering for AI working on `rouxtaccess/laravel-sync`. Read the page that matches your task.

- [Architecture](architecture.md): the plugin model, the registries, the run flow, and the data shape of a group.
- [Extending](extending.md): how to add a sync type, a database driver, an after-hook, or an anonymizer.
- [Testing](testing.md): how the suite is structured and how to test process-driven code.

## Ground rules (repeated from the root CLAUDE.md)

- Keep `composer test`, `vendor/bin/phpstan analyse`, and `vendor/bin/pint` green.
- PHP 8.2 syntax floor, Laravel 10 to 13. Spatie PHP conventions (protected over private, early returns, guard clauses).
- Documentation must not use dashes as punctuation.
- Never write code that pushes data upstream. A sync only creates local databases and copies downward.
- Secrets live in the gitignored `sync-jobs.json`. Never commit or print it.
