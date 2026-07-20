# Testing

The suite is Pest on top of Orchestra Testbench. `tests/TestCase.php` registers `SyncServiceProvider` and points `sync.store` at a temp file. Run it with `composer test`.

## What is covered

| File | Focus |
| --- | --- |
| `GroupStoreTest` | JSON round trip, pretty printing, malformed tolerance, flat-to-nested migration |
| `RegistriesTest` | resolution and unknown-key errors for all three registries |
| `FieldTest` | default and cast closures |
| `SqliteDriverTest` | path mapping, existence, portless behaviour |
| `DriversTest` | mysql/pgsql/sqlite command construction, the `--verbose` flag, the progress markers (`countTablesCommand()`, `dumpProgressPattern()`, `dataMarker()`), and verbose-vs-error classification (`isDumpNoise()`) |
| `SyncTypesTest` | command construction via `Process::fake()`, sqlite rejection, the DB fetch/load pipeline, target-exists abort before any fetch |
| `DumpStoreTest` | timestamped paths, newest-first ordering, prune retention, the slug-prefix glob guard |
| `FetchesAndLoadsDatabaseTest` | the trait: marker parsing, table counting, reuse prompt, a real dump-file to import round trip, and the failed-import rollback |
| `ProgressTest` | both real reporters and the line-splitting/flush in `StreamsProcessProgress` |
| `HooksTest` | applies-to gating and the `.env` rewrite |
| `AnonymizersTest` | real scrubbing against an in-memory database |
| `RunSyncCommandTest` | the guard, `--force`, and a full faked run |
| `InstallCommandTest` | config publish and store seeding |

## Testing process-driven code

The sync types shell out. Assert on the command rather than executing it. `run()` takes a required third argument, the `ProgressReporter`; pass a `NullProgressReporter` (the suite wraps it in a `progress()` helper). Because a fetch/load type runs several processes, `SyncTypesTest` reads the recorded commands directly (a `ranCommands()` helper reflecting the fake's `recorded` property) rather than relying on `Process::assertRan()`, which short-circuits on the first match:

```php
Process::fake();

$result = (new FilesOverSshSyncType)->run([
    'config' => ['ssh' => 'forge@1.2.3.4', 'remote_path' => '/srv/app', 'local_path' => 'storage', 'delete' => true],
], false, new NullProgressReporter);

expect(ranCommand())->toContain('rsync', '--delete');
```

To simulate a failure, return a failed `Process::result()`:

```php
Process::fake(['*' => Process::result(errorOutput: 'ssh: connect refused', exitCode: 1)]);
```

## Testing the database fetch and load

A `db-over-ssh` or `db-from-s3` run fetches a dump to a file, then imports it, so point `sync.dumps.path` at a temp directory first (`useTempDumps()` sets it to a unique temp dir; `afterEach` deletes it). With `Process::fake()` and a non-interactive run (so it always fetches fresh), assert on the two halves in the recorded commands:

- **Fetch**: `mysqldump` runs verbosely and is redirected to a `.sql` dump file (`mysqldump ... --verbose ... > ...sql`). For `db-from-s3`, `aws s3 cp ... | gunzip ... > ...sql`.
- **Load**: the dump file is streamed through `awk` that tees per-table markers to `/dev/stderr`, piped into the local client (`awk ... /dev/stderr ... | mysql`).

```php
useTempDumps();
Process::fake();

$result = (new DbOverSshSyncType)->run(['name' => 'db', 'config' => [/* mysql config */]], false, new NullProgressReporter);

expect(collect(ranCommands())->contains(fn (string $c): bool => str_contains($c, 'mysqldump')
    && str_contains($c, '--verbose') && str_contains($c, '> ') && str_contains($c, '.sql')))->toBeTrue();
```

## Testing streaming, progress, and prompts

Important limitation: `Process::fake()` never invokes the output callback passed to `Process::run()`. A faked run therefore validates only the command strings and the final `SyncResult`, never the streaming behaviour (line splitting, progress advancement, the trailing-line flush, the marker-filtered error). To exercise that code, use a real, deterministic process (`bash`, `printf`) or a stub driver whose commands are real. See `ProgressTest` and `FetchesAndLoadsDatabaseTest`.

- **Line splitting and flush** (`StreamsProcessProgress`): drive `streamProcess()` with `['bash', '-c', 'printf "a\nb"']` and assert the collected lines. The final unterminated line is delivered by the end-of-stream flush.
- **A real load** (`loadDump()`): give a stub `DatabaseDriver` an `importCommand()` of `cat > <file>`, write a dump file with `dataMarker()` lines, and assert the imported file equals the dump and a spy `ProgressReporter` advanced once per table.
- **A failed import**: point the stub's `importCommand()` at a command that prints to stderr and exits non-zero, wrap the run in `Prompt::fake()`, and use `Prompt::assertOutputContains()` / `assertOutputDoesntContain()` to confirm the real error shows and the teed markers do not.
- **The reuse prompt** (`chooseDump()`): `Prompt::fake([Key::ENTER])` accepts the default (reuse); `Prompt::fake([Key::DOWN, Key::ENTER])` picks "fresh".
- **The prompts bar** (`PromptsProgressReporter`): wrap in `Prompt::fake()` and read the protected `bar` (a `Laravel\Prompts\Progress`) by reflection to assert `total`/`progress`.

## Testing the guard

The default allowed environments include `testing`, so set the list explicitly to make the guard fire:

```php
config()->set('sync.guard.allowed_environments', ['local']);
$this->artisan('rouxt:sync', ['group' => 'production', '--yes' => true])->assertExitCode(1);
Process::assertNothingRan();
```

## Testing database code

Anonymizers and database-touching hooks can run against the default in-memory SQLite connection. Build a table with the schema builder, insert rows, run the code, and assert on the rows. See `AnonymizersTest`.

## Verifying in a real app

`vendor/bin/testbench` boots a real app with the provider (see `testbench.yaml`). Use it to confirm wiring:

```bash
vendor/bin/testbench rouxt:sync --help
vendor/bin/testbench rouxt:sync demo --no-interaction --env=production   # expect a guard refusal
```

Actual data transfer needs real SSH keys, AWS credentials, and network access, which the test environment does not have. That end to end check belongs to a developer with production access.
