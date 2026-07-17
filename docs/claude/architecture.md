# Architecture

The package is a small plugin framework. Four kinds of class are registered in `config/sync.php` and resolved through registries bound in `SyncServiceProvider`.

| Concept | Contract | Registry | Registered in config |
| --- | --- | --- | --- |
| Sync type | `Contracts\SyncType` | `Registries\SyncTypeRegistry` | `types` |
| Database engine | `Contracts\DatabaseDriver` | `Registries\DatabaseDriverRegistry` | `database_drivers` |
| After-hook | `Contracts\AfterHook` | `Registries\AfterHookRegistry` | `after_hooks` |
| Anonymizer | invokable class or SQL string | read by `AnonymizeDatabaseHook` | `anonymizers` |

Supporting pieces:

- `Field`: a value object describing one prompt (label, secret, options, default and cast closures). A sync type returns an array of these from `fields()`.
- `SyncResult`: an immutable `ok` / `message` / `data` result. `data` carries the imported database name to the after-hooks.
- `GroupStore`: plain-JSON persistence for groups. Reads and writes strict JSON; a missing or malformed file yields an empty collection. It normalizes older flat jobs (fields at the top level) into the nested `config` shape on read, and `migrate()` rewrites the file in place; `rouxt:sync` calls it on run. `rouxt:sync-install` drops a valid-JSON `sync-jobs.example.json` reference beside it.

## Components

```mermaid
flowchart TD
    subgraph Command["RunSyncCommand (rouxt:sync)"]
        Guard{"Environment guard"}
        Wizard["Add group / job wizard"]
        Plan["Plan table + confirm"]
    end

    Guard -->|allowed or --force| Store["GroupStore (sync-jobs.json)"]
    Wizard --> Store
    Store --> Plan
    Plan --> Type["SyncType.run(job, interactive)"]

    Type --> Driver["DatabaseDriver"]
    Type --> Hooks["AfterHook (offered up front, run at the end)"]
    Hooks --> Anon["Anonymizers (config)"]
    Type --> Result["SyncResult"]
```

## The run flow for db-over-ssh

This is the richest path. The other types follow the same `run()` shape with a different pipeline.

```mermaid
sequenceDiagram
    participant Dev as Developer
    participant Cmd as rouxt:sync
    participant Type as DbOverSshSyncType
    participant Drv as DatabaseDriver
    participant Ssh as SSH tunnel

    Dev->>Cmd: rouxt:sync production
    Cmd->>Cmd: guard environment
    Cmd->>Type: run(job, interactive)
    Type->>Drv: databaseExists() then resolve target name
    Type->>Type: plan after-hooks (ask now, run later)
    Type->>Ssh: open tunnel (ssh -f -N -M -L 127.0.0.1:port)
    Type->>Drv: createDatabase(target)
    Type->>Ssh: dump | sanitize | import (through the tunnel)
    Type->>Ssh: close tunnel (finally)
    alt import failed
        Type->>Drv: dropDatabase(target)
        Type-->>Cmd: SyncResult::failure
    else import ok
        Type->>Type: run after-hooks (swap env, migrate, anonymize)
        Type-->>Cmd: SyncResult::success(database)
    end
```

Key invariants:

- The tunnel is always closed in a `finally` block.
- On failure the half-created database is dropped; an existing database is never dropped unless the user explicitly picks "replace".
- With `--yes` (non-interactive) a name clash aborts rather than overwriting, and no after-hooks run.

## Data shape of a group

A group is a named list of jobs in `sync-jobs.json`. A job has top-level `name` and `type`, a `config` object holding the fields its type declares, and an optional `after` allow-list of hook keys. `run(array $job, ...)` and `summary(array $job)` receive the whole job; field access is `$job['config'][...]`.

```mermaid
erDiagram
    GROUP ||--o{ JOB : contains
    JOB ||--|| CONFIG : "has"
    CONFIG ||--o{ FIELD : "type-specific fields"
    JOB {
        string name
        string type
        array after "hook keys"
    }
    GROUP {
        string name
    }
```

## Where things live

```
src/
  SyncServiceProvider.php      registries bound here from config
  Field.php  SyncResult.php  GroupStore.php
  Contracts/                   SyncType, DatabaseDriver, AfterHook
  Registries/                  one per contract
  Concerns/                    shared traits for types and hooks
  Types/                       db-over-ssh, db-from-s3, files-over-ssh, s3-sync
  Database/Drivers/            MysqlDriver, PostgresDriver, SqliteDriver
  Hooks/                       swap env, run migrations, anonymize
  Anonymizers/                 reusable example scrubbers
  Commands/                    RunSyncCommand, InstallCommand
```
