# Security Policy

## Reporting a vulnerability

Please do not open a public issue for security problems. Email john.roux1@gmail.com with the details and steps to reproduce. You will get an acknowledgement, and a fix and disclosure will be coordinated with you.

## What to keep in mind

This is a developer tool that shells out to `ssh`, `rsync`, `mysqldump`, `pg_dump`, `sqlite3`, and the `aws` CLI using connection details from a local store.

- The `sync-jobs.json` store holds plaintext credentials. It is gitignored by `rouxt:sync-install`. Keep it out of version control and off shared channels.
- Values interpolated into shell commands are passed through `escapeshellarg`, and passwords are passed to clients via environment variables rather than the argument list. If you find a place where a store value reaches a shell unescaped, treat it as a vulnerability and report it.
- The tool only reads from upstream and writes locally. A change that lets it write to a production or upstream source is a security concern.

## Supported versions

Security fixes are applied to the latest released major version.
