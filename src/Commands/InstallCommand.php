<?php

namespace Rouxtaccess\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

class InstallCommand extends Command
{
    protected $signature = 'rouxt:sync-install';

    protected $description = 'Publish the sync config, drop an example file, and gitignore the store';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'sync-config']);
        note('Published config/sync.php.');

        $this->seedExample();
        $this->ignore(basename((string) config('sync.store')));
        $this->ignore(basename((string) config('sync.dumps.path')).'/');

        note('Done. Run `php artisan rouxt:sync` to configure and run a group.');

        return self::SUCCESS;
    }

    /**
     * Drop a valid-JSON example next to the store so it can be opened, copied,
     * or committed as a team reference. The real store is created by the wizard.
     */
    protected function seedExample(): void
    {
        $example = dirname((string) config('sync.store')).'/sync-jobs.example.json';

        if (File::exists($example)) {
            warning('Example already exists at '.$example.'; left untouched.');

            return;
        }

        File::ensureDirectoryExists(dirname($example));
        File::copy(__DIR__.'/../../stubs/sync-jobs.example.json', $example);
        note('Wrote example groups to '.$example.'.');
    }

    protected function ignore(string $entry): void
    {
        $gitignore = base_path('.gitignore');

        if (! File::exists($gitignore)) {
            File::put($gitignore, $entry."\n");
            note("Created .gitignore with {$entry}.");

            return;
        }

        $contents = File::get($gitignore);

        if (Str::contains($contents, $entry)) {
            return;
        }

        File::append($gitignore, (Str::endsWith($contents, "\n") ? '' : "\n").$entry."\n");
        note("Added {$entry} to .gitignore.");
    }
}
