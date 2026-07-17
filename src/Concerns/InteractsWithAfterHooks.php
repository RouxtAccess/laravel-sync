<?php

namespace Rouxtaccess\Sync\Concerns;

use Rouxtaccess\Sync\Contracts\AfterHook;
use Rouxtaccess\Sync\Registries\AfterHookRegistry;

use function Laravel\Prompts\note;

trait InteractsWithAfterHooks
{
    /**
     * Ask up front which applicable after-hooks to run once the job succeeds,
     * returning the chosen hooks to defer to the end of the run.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $context
     * @return array<int, AfterHook>
     */
    protected function planAfterHooks(array $job, array $context, bool $interactive): array
    {
        if (! $interactive) {
            return [];
        }

        return array_values(array_filter(
            $this->applicableHooks($job),
            fn (AfterHook $hook): bool => $hook->prompt($job, $context, $interactive),
        ));
    }

    /**
     * @param  array<int, AfterHook>  $hooks
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $context
     */
    protected function runAfterHooks(array $hooks, array $job, array $context): void
    {
        foreach ($hooks as $hook) {
            note($hook->handle($job, $context));
        }
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<int, AfterHook>
     */
    protected function applicableHooks(array $job): array
    {
        $enabled = $job['after'] ?? null;

        return collect(app(AfterHookRegistry::class)->all())
            ->filter(fn (AfterHook $hook, string $key): bool => ($enabled === null || in_array($key, $enabled, true)) && $hook->appliesToJob($job))
            ->values()
            ->all();
    }
}
