<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Rouxtaccess\Sync\Concerns\FetchesAndLoadsDatabase;
use Rouxtaccess\Sync\Contracts\DatabaseDriver;
use Rouxtaccess\Sync\Contracts\ProgressReporter;
use Rouxtaccess\Sync\Database\DumpStore;
use Rouxtaccess\Sync\Progress\NullProgressReporter;
use Rouxtaccess\Sync\SyncResult;

/**
 * Exposes the trait's protected helpers so they can be exercised directly.
 */
class FetchLoadHarness
{
    use FetchesAndLoadsDatabase;

    public function tableName(string $line): string
    {
        return $this->tableFromMarker($line);
    }

    public function countTables(DatabaseDriver $driver, string $dumpFile): ?int
    {
        return $this->countTablesInDump($driver, $dumpFile);
    }

    public function choose(string $job, bool $interactive): ?string
    {
        return $this->chooseDump($job, $interactive);
    }

    public function load(DatabaseDriver $driver, string $dumpFile, string $target, ProgressReporter $progress): SyncResult
    {
        return $this->loadDump($driver, ['name' => 'db', 'config' => []], $dumpFile, $target, false, $progress);
    }
}

/**
 * A driver whose import command fails loudly: it echoes a real error to stderr
 * (mixed in with the awk-teed table markers) and exits non-zero.
 */
function failingDriver(): DatabaseDriver
{
    return new class implements DatabaseDriver
    {
        public bool $dropped = false;

        public static function key(): string
        {
            return 'fake';
        }

        public static function label(): string
        {
            return 'Fake';
        }

        public function defaultPort(): int
        {
            return 3306;
        }

        public function createDatabase(string $database): ProcessResult
        {
            return Process::result();
        }

        public function dropDatabase(string $database): void
        {
            $this->dropped = true;
        }

        public function databaseExists(string $database): bool
        {
            return false;
        }

        public function dumpCommand(array $remote, int $port, bool $verbose = false): string
        {
            return 'true';
        }

        public function countTablesCommand(array $remote, int $port): ?string
        {
            return null;
        }

        public function dumpProgressPattern(): ?string
        {
            return null;
        }

        public function dataMarker(): ?string
        {
            return '^-- MARK';
        }

        public function importCommand(string $database): string
        {
            return "cat >/dev/null; printf 'REALERR-boom\\n' >&2; exit 1";
        }

        public function isDumpNoise(string $line): bool
        {
            return false;
        }

        public function sanitizePipe(): ?string
        {
            return null;
        }
    };
}

/**
 * Records every progress call so the load path's wiring can be asserted.
 */
class SpyProgressReporter implements ProgressReporter
{
    public ?int $startTotal = null;

    /** @var array<int, ?string> */
    public array $details = [];

    public bool $finished = false;

    public function start(string $label, ?int $total = null): void
    {
        $this->startTotal = $total;
    }

    public function setTotal(int $total): void {}

    public function advance(int $step = 1, ?string $detail = null): void
    {
        $this->details[] = $detail;
    }

    public function finish(?string $message = null): void
    {
        $this->finished = true;
    }
}

/**
 * A driver whose import writes stdin to a real file, so a load can be verified
 * end to end (dump file in, imported bytes out) with real processes.
 */
function writingDriver(string $target): DatabaseDriver
{
    return new class($target) implements DatabaseDriver
    {
        public function __construct(protected string $target) {}

        public static function key(): string
        {
            return 'writing';
        }

        public static function label(): string
        {
            return 'Writing';
        }

        public function defaultPort(): int
        {
            return 3306;
        }

        public function createDatabase(string $database): ProcessResult
        {
            return Process::result();
        }

        public function dropDatabase(string $database): void {}

        public function databaseExists(string $database): bool
        {
            return false;
        }

        public function dumpCommand(array $remote, int $port, bool $verbose = false): string
        {
            return 'true';
        }

        public function countTablesCommand(array $remote, int $port): ?string
        {
            return null;
        }

        public function dumpProgressPattern(): ?string
        {
            return null;
        }

        public function dataMarker(): ?string
        {
            return '^-- MARK';
        }

        public function importCommand(string $database): string
        {
            return 'cat > '.escapeshellarg($this->target);
        }

        public function isDumpNoise(string $line): bool
        {
            return false;
        }

        public function sanitizePipe(): ?string
        {
            return null;
        }
    };
}

it('streams a real dump file through the import and advances progress per table', function () {
    $dump = "-- MARK table `users`\nINSERT INTO users VALUES (1);\n-- MARK table `posts`\nINSERT INTO posts VALUES (1);\n";
    $dumpFile = sys_get_temp_dir().'/rouxt-e2e-dump-'.getmypid().'.sql';
    $imported = sys_get_temp_dir().'/rouxt-e2e-imported-'.getmypid().'.sql';
    File::put($dumpFile, $dump);
    File::delete($imported);

    $spy = new SpyProgressReporter;
    $result = (new FetchLoadHarness)->load(writingDriver($imported), $dumpFile, 'app_2026_07_20', $spy);

    expect($result->ok)->toBeTrue();

    // The whole dump was streamed through the import, unchanged.
    expect(File::exists($imported))->toBeTrue()
        ->and(File::get($imported))->toBe($dump);

    // Progress was sized from the file's markers and advanced once per table.
    expect($spy->startTotal)->toBe(2)
        ->and($spy->details)->toBe(['users', 'posts'])
        ->and($spy->finished)->toBeTrue();

    File::delete($dumpFile);
    File::delete($imported);
});

it('reads the table name from both a mysql and a postgres marker', function () {
    $harness = new FetchLoadHarness;

    expect($harness->tableName('-- Dumping data for table `users`'))->toBe('users')
        ->and($harness->tableName('-- Data for Name: users; Type: TABLE DATA; Schema: public'))->toBe('users');
});

it('counts the data markers in a dump file', function () {
    $file = sys_get_temp_dir().'/rouxt-count-'.getmypid().'.sql';
    File::put($file, "-- MARK table a\nINSERT 1;\n-- MARK table b\nINSERT 2;\n");

    // failingDriver()'s dataMarker() is '^-- MARK'; only that method matters here.
    expect((new FetchLoadHarness)->countTables(failingDriver(), $file))->toBe(2);

    File::delete($file);
});

it('rolls back and reports only the real error, not the teed table markers, on a failed import', function () {
    Prompt::fake();

    $file = sys_get_temp_dir().'/rouxt-fail-'.getmypid().'.sql';
    File::put($file, "-- MARK table a\nINSERT 1;\n-- MARK table b\nINSERT 2;\n");

    $driver = failingDriver();
    $result = (new FetchLoadHarness)->load($driver, $file, 'app_2026_07_20', new NullProgressReporter);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('dropped')
        ->and($driver->dropped)->toBeTrue();

    Prompt::assertOutputContains('REALERR-boom');
    Prompt::assertOutputDoesntContain('MARK');

    File::delete($file);
});

it('reuses, refuses, or skips a cached dump per the interactive choice', function () {
    config()->set('sync.dumps.path', sys_get_temp_dir().'/rouxt-choose-'.getmypid());
    $store = DumpStore::fromConfig();
    $latest = $store->pathFor('db', '2026_07_20_100000');
    File::put($latest, '-- dump');

    $harness = new FetchLoadHarness;

    // Non-interactive always pulls fresh, without prompting.
    expect($harness->choose('db', false))->toBeNull();

    // Interactive: Enter accepts the default (reuse) and returns the cached path.
    Prompt::fake([Key::ENTER]);
    expect($harness->choose('db', true))->toBe($latest);

    // Interactive: choosing "fresh" returns null so the caller re-fetches.
    Prompt::fake([Key::DOWN, Key::ENTER]);
    expect($harness->choose('db', true))->toBeNull();

    File::deleteDirectory(config('sync.dumps.path'));
});

it('does not prompt to reuse when no cached dump exists', function () {
    config()->set('sync.dumps.path', sys_get_temp_dir().'/rouxt-empty-'.getmypid());

    expect((new FetchLoadHarness)->choose('db', true))->toBeNull();
});
