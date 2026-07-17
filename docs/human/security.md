# Security

## The store file holds secrets

`sync-jobs.json` stores connection details in plaintext, including database passwords. `rouxt:sync-install` adds it to `.gitignore`. Keep it out of version control, and do not paste its contents into chats, issues, or logs.

Prefer credentials that do not sit in the file:

- Use SSH keys and an SSH agent rather than inline passwords for `db-over-ssh` and `files-over-ssh`.
- Use named AWS profiles rather than inline keys for `db-from-s3` and `s3-sync`.

## The environment guard

The command refuses to run outside `guard.allowed_environments`. Keep production off that list. `--force` exists for the rare case where you must override, and it prints a loud warning when used.

## Anonymize before you share

If a local copy of production data will be seen by more people than production is, enable the `anonymize` hook and configure anonymizers for every table that holds personal data (emails, phone numbers, tokens, payment details). The shipped examples cover common `users` columns; add your own for the rest.

## Downward only

By design the tool never writes to production. It opens read-only dumps and one way copies, and it only creates new local databases. If you are reviewing a change to this package, treat any new write path toward a remote source as a bug.
