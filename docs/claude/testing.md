# Testing

The suite is Pest on top of Orchestra Testbench. `tests/TestCase.php` registers `SyncServiceProvider` and points `sync.store` at a temp file. Run it with `composer test`.

## What is covered

| File | Focus |
| --- | --- |
| `GroupStoreTest` | JSON round trip, pretty printing, malformed tolerance, flat-to-nested migration |
| `RegistriesTest` | resolution and unknown-key errors for all three registries |
| `FieldTest` | default and cast closures |
| `SqliteDriverTest` | path mapping, existence, portless behaviour |
| `SyncTypesTest` | command construction via `Process::fake()`, sqlite rejection |
| `HooksTest` | applies-to gating and the `.env` rewrite |
| `AnonymizersTest` | real scrubbing against an in-memory database |
| `RunSyncCommandTest` | the guard, `--force`, and a full faked run |
| `InstallCommandTest` | config publish and store seeding |

## Testing process-driven code

The sync types shell out. Assert on the command rather than executing it:

```php
Process::fake();

(new FilesOverSshSyncType)->run([...], false);

Process::assertRan(function ($process) {
    $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

    return str_contains($command, 'rsync') && str_contains($command, '--delete');
});
```

To simulate a failure, return a failed `Process::result()`:

```php
Process::fake(['*' => Process::result(errorOutput: 'ssh: connect refused', exitCode: 1)]);
```

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
