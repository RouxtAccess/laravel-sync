<?php

namespace Rouxtaccess\Sync\Registries;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Rouxtaccess\Sync\Contracts\DatabaseDriver;

class DatabaseDriverRegistry
{
    /**
     * @var Collection<string, DatabaseDriver>
     */
    protected Collection $drivers;

    /**
     * @param  array<int, class-string<DatabaseDriver>>  $drivers
     */
    public function __construct(array $drivers)
    {
        $this->drivers = collect($drivers)
            ->map(fn (string $class): DatabaseDriver => app($class))
            ->keyBy(fn (DatabaseDriver $driver): string => $driver::key());
    }

    public function has(string $key): bool
    {
        return $this->drivers->has($key);
    }

    public function get(string $key): DatabaseDriver
    {
        return $this->drivers->get($key) ?? throw new InvalidArgumentException("Unknown database driver [{$key}].");
    }

    /**
     * Key => label, for the engine picker.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->drivers->map(fn (DatabaseDriver $driver): string => $driver::label())->all();
    }
}
