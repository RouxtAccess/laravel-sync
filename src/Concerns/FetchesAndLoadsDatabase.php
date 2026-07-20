<?php

namespace Rouxtaccess\Sync\Concerns;

use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Contracts\DatabaseDriver;
use Rouxtaccess\Sync\Contracts\ProgressReporter;
use Rouxtaccess\Sync\Database\DumpStore;
use Rouxtaccess\Sync\SyncResult;

use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

/**
 * Shared machinery for database sync types that split the work into a fetch (pull
 * a dump to a local file) and a load (import that file). Keeping the two apart
 * lets a developer pull once and import repeatedly for testing, and gives the
 * load a local file to pre-scan so it can show real per-table progress.
 *
 * The composing type provides its own fetch (a mysqldump over a tunnel, or an S3
 * download) and reuses loadDump() for the import.
 */
trait FetchesAndLoadsDatabase
{
    use InteractsWithAfterHooks;
    use InteractsWithDatabaseDriver;
    use StreamsProcessProgress;

    /**
     * Decide whether to reuse an existing dump. Returns a path to reuse, or null
     * to fetch a fresh one. Only prompts interactively; --yes always pulls fresh.
     */
    protected function chooseDump(string $job, bool $interactive): ?string
    {
        if (! $interactive) {
            return null;
        }

        $latest = $this->dumpStore()->latest($job);

        if ($latest === null) {
            return null;
        }

        $when = date('Y-m-d H:i', (int) filemtime($latest));

        $choice = select(
            label: "A dump for '{$job}' from {$when} exists.",
            options: [
                'reuse' => 'Reuse it (skip production)',
                'fresh' => 'Pull a fresh dump',
            ],
            default: 'reuse',
        );

        return $choice === 'reuse' ? $latest : null;
    }

    /**
     * Resolve the local database to import into, or null when the run should
     * abort because the target already exists and the user declined to replace
     * it. Called before any fetch so a production dump is never pulled for a run
     * that will only be turned away.
     *
     * @param  array<string, mixed>  $config
     */
    protected function resolveLoadTarget(DatabaseDriver $driver, array $config, bool $interactive): ?string
    {
        return $this->resolveTarget($driver, $this->targetDatabase($config), $interactive);
    }

    /**
     * Create the target database and import a dump file into it, reporting
     * per-table progress, running after-hooks, and rolling back on failure. The
     * target is resolved up front (see resolveLoadTarget()) and passed in.
     *
     * @param  array<string, mixed>  $job
     */
    protected function loadDump(DatabaseDriver $driver, array $job, string $dumpFile, string $target, bool $interactive, ProgressReporter $progress): SyncResult
    {
        $context = ['database' => $target];
        $hooks = $this->planAfterHooks($job, $context, $interactive);

        if ($driver->createDatabase($target)->failed()) {
            return SyncResult::failure("Could not create local database {$target}.");
        }

        $progress->start("Importing into {$target}", $this->countTablesInDump($driver, $dumpFile));
        $pattern = $this->toPcre($driver->dataMarker());
        $errorLines = [];

        $result = $this->streamProcess(['bash', '-c', $this->importPipeline($driver, $dumpFile, $target)], function (string $stream, string $line) use ($progress, $pattern, &$errorLines): void {
            if ($pattern !== null && preg_match($pattern, $line) === 1) {
                $progress->advance(1, $this->tableFromMarker($line));

                return;
            }

            if ($stream === 'err') {
                $errorLines[] = $line;
            }
        });

        if ($result->failed()) {
            $progress->finish();
            $driver->dropDatabase($target);
            note(trim(implode(PHP_EOL, $errorLines)) ?: 'No error output was captured.');

            return SyncResult::failure('Import failed. The half-created database was dropped.');
        }

        $progress->finish("Imported into {$target}.");
        $this->runAfterHooks($hooks, $job, $context);

        return SyncResult::success("Imported into {$target}.", ['database' => $target]);
    }

    /**
     * Stream the dump file to the importer, teeing each per-table data marker to
     * stderr so the import can advance a bar. When the engine exposes no marker,
     * the file is imported straight and progress runs indeterminate.
     */
    protected function importPipeline(DatabaseDriver $driver, string $dumpFile, string $target): string
    {
        $import = $driver->importCommand($target);
        $marker = $driver->dataMarker();

        if ($marker === null) {
            return 'set -o pipefail; '.$import.' < '.escapeshellarg($dumpFile);
        }

        $awk = 'awk '.escapeshellarg('/'.$marker.'/ { print > "/dev/stderr" } { print }').' '.escapeshellarg($dumpFile);

        return 'set -o pipefail; '.$awk.' | '.$import;
    }

    /**
     * Count the tables in a dump file (its per-table data markers) to size the
     * import bar. Null when the engine has no marker.
     */
    protected function countTablesInDump(DatabaseDriver $driver, string $dumpFile): ?int
    {
        $marker = $driver->dataMarker();

        if ($marker === null) {
            return null;
        }

        $result = Process::run(['bash', '-c', 'grep -cE '.escapeshellarg($marker).' '.escapeshellarg($dumpFile)]);
        $count = (int) trim($result->output());

        return $count > 0 ? $count : null;
    }

    protected function dumpStore(): DumpStore
    {
        return DumpStore::fromConfig();
    }

    /**
     * @param  array<string, mixed>  $job
     */
    protected function jobName(array $job): string
    {
        return (string) ($job['name'] ?? $job['type'] ?? 'db');
    }

    /**
     * Turn a driver's POSIX ERE fragment into a delimited PCRE for preg_match.
     * The `#` delimiter avoids escaping the `/` none of the markers contain.
     */
    protected function toPcre(?string $ere): ?string
    {
        return $ere === null ? null : '#'.$ere.'#';
    }

    /**
     * Pull a readable table name out of a dump marker line for the progress
     * detail, falling back to the trimmed line.
     */
    protected function tableFromMarker(string $line): string
    {
        // Postgres markers read "Data for Name: <table>; Type: TABLE DATA"; match
        // this first so the "TABLE DATA" in the same line is not mistaken for the
        // table name.
        if (preg_match('/Name: ([^;]+)/', $line, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/table [`"]?([^`"\s;]+)/i', $line, $matches) === 1) {
            return $matches[1];
        }

        return $line;
    }
}
