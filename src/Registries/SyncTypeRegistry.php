<?php

namespace Rouxtaccess\Sync\Registries;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Rouxtaccess\Sync\Contracts\SyncType;

class SyncTypeRegistry
{
    /**
     * @var Collection<string, SyncType>
     */
    protected Collection $types;

    /**
     * @param  array<int, class-string<SyncType>>  $types
     */
    public function __construct(array $types)
    {
        $this->types = collect($types)
            ->map(fn (string $class): SyncType => app($class))
            ->keyBy(fn (SyncType $type): string => $type::key());
    }

    /**
     * @return Collection<string, SyncType>
     */
    public function all(): Collection
    {
        return $this->types;
    }

    public function has(string $key): bool
    {
        return $this->types->has($key);
    }

    public function get(string $key): SyncType
    {
        return $this->types->get($key) ?? throw new InvalidArgumentException("Unknown sync type [{$key}].");
    }

    /**
     * Key => label, for the "add a job" type picker.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->types->map(fn (SyncType $type): string => $type::label())->all();
    }
}
