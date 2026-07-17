<?php

namespace Rouxtaccess\Sync\Hooks;

use Illuminate\Support\Facades\File;
use Rouxtaccess\Sync\Contracts\AfterHook;

use function Laravel\Prompts\confirm;

class SwapEnvDatabaseHook implements AfterHook
{
    public static function key(): string
    {
        return 'swap-env-database';
    }

    public static function label(): string
    {
        return 'Point .env DB_DATABASE at the imported database';
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

        return confirm("After importing, point your .env DB_DATABASE at {$database}?", default: false);
    }

    public function handle(array $job, array $context): string
    {
        $database = $context['database'];
        $path = app()->environmentFilePath();
        $contents = File::exists($path) ? File::get($path) : '';
        $line = "DB_DATABASE={$database}";

        if (preg_match('/^DB_DATABASE=.*/m', $contents) === 1) {
            File::put($path, preg_replace('/^DB_DATABASE=.*/m', $line, $contents));

            return "Updated .env DB_DATABASE={$database}.";
        }

        File::put($path, rtrim($contents, "\n")."\n".$line."\n");

        return "Updated .env DB_DATABASE={$database}.";
    }
}
