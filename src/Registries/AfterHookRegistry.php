<?php

namespace Rouxtaccess\Sync\Registries;

use Illuminate\Support\Collection;
use Rouxtaccess\Sync\Contracts\AfterHook;

class AfterHookRegistry
{
    /**
     * @var Collection<string, AfterHook>
     */
    protected Collection $hooks;

    /**
     * @param  array<int, class-string<AfterHook>>  $hooks
     */
    public function __construct(array $hooks)
    {
        $this->hooks = collect($hooks)
            ->map(fn (string $class): AfterHook => app($class))
            ->keyBy(fn (AfterHook $hook): string => $hook::key());
    }

    /**
     * @return Collection<string, AfterHook>
     */
    public function all(): Collection
    {
        return $this->hooks;
    }

    /**
     * Applicable hooks for a job, as key => label (for the add-wizard picker).
     *
     * @param  array<string, mixed>  $job
     * @return array<string, string>
     */
    public function applicableTo(array $job): array
    {
        return $this->hooks
            ->filter(fn (AfterHook $hook): bool => $hook->appliesToJob($job))
            ->map(fn (AfterHook $hook): string => $hook::label())
            ->all();
    }
}
