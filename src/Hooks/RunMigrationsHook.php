<?php

namespace Rouxtaccess\Sync\Hooks;

use Illuminate\Support\Facades\Artisan;
use Rouxtaccess\Sync\Concerns\ConnectsToImportedDatabase;
use Rouxtaccess\Sync\Contracts\AfterHook;

use function Laravel\Prompts\confirm;

class RunMigrationsHook implements AfterHook
{
    use ConnectsToImportedDatabase;

    public static function key(): string
    {
        return 'run-migrations';
    }

    public static function label(): string
    {
        return 'Run outstanding migrations on the imported database';
    }

    public function appliesToJob(array $job): bool
    {
        return str_starts_with($job['type'] ?? '', 'db-');
    }

    public function prompt(array $job, array $context, bool $interactive): bool
    {
        $database = $context['database'] ?? null;

        if (! $interactive || $database === null) {
            return false;
        }

        return confirm("After importing, run outstanding migrations on {$database}?", default: false);
    }

    public function handle(array $job, array $context): string
    {
        return $this->onImportedDatabase($job, $context, function (string $connection) use ($context): string {
            Artisan::call('migrate', [
                '--database' => $connection,
                '--force' => true,
            ]);

            return "Ran migrations on {$connection} ({$context['database']}).";
        });
    }
}
