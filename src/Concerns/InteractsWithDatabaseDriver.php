<?php

namespace Rouxtaccess\Sync\Concerns;

use Rouxtaccess\Sync\Contracts\DatabaseDriver;
use Rouxtaccess\Sync\Database\Drivers\MysqlDriver;
use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\Registries\DatabaseDriverRegistry;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait InteractsWithDatabaseDriver
{
    /**
     * Decide the final local database name to import into, handling the case
     * where the intended target already exists. Returns null to abort.
     */
    protected function resolveTarget(DatabaseDriver $driver, string $target, bool $interactive): ?string
    {
        if (! $driver->databaseExists($target)) {
            return $target;
        }

        if (! $interactive) {
            return null;
        }

        $choice = select(
            label: "Local database {$target} already exists. What would you like to do?",
            options: [
                'abort' => 'Abort — leave it and do nothing',
                'replace' => "Replace — drop {$target} and re-import",
                'rename' => 'Rename — import under a different name',
            ],
            default: 'abort',
        );

        if ($choice === 'abort') {
            return null;
        }

        if ($choice === 'replace') {
            $driver->dropDatabase($target);

            return $target;
        }

        return text(
            label: 'Import as which database name?',
            default: $target.'_copy',
            required: true,
            validate: fn (string $value): ?string => $driver->databaseExists(trim($value))
                ? "Database {$value} also exists. Choose another name."
                : null,
            transform: fn (string $value): string => trim($value),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function driver(array $config): DatabaseDriver
    {
        return $this->driverRegistry()->get($config['driver'] ?? MysqlDriver::key());
    }

    protected function driverRegistry(): DatabaseDriverRegistry
    {
        return app(DatabaseDriverRegistry::class);
    }

    protected function driverField(): Field
    {
        return new Field('driver', 'Database engine', options: $this->driverRegistry()->options(), default: MysqlDriver::key());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function targetDatabase(array $config): string
    {
        $prefix = data_get($config, 'target_prefix') ?: data_get($config, 'db_name');

        return $prefix.'_'.now()->format('Y_m_d');
    }
}
