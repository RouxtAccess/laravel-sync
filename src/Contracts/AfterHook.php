<?php

namespace Rouxtaccess\Sync\Contracts;

interface AfterHook
{
    public static function key(): string;

    public static function label(): string;

    /**
     * Whether this hook is relevant to the given job (used in the add-wizard
     * and as a run-time filter).
     *
     * @param  array<string, mixed>  $job
     */
    public function appliesToJob(array $job): bool;

    /**
     * Ask the user up front (before the job runs) whether to run this hook once
     * the job succeeds. Return true to defer it to the end of the run.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $context  known details, e.g. the resolved target database
     */
    public function prompt(array $job, array $context, bool $interactive): bool;

    /**
     * Perform the hook after the job has succeeded; returns a status message.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $context
     */
    public function handle(array $job, array $context): string;
}
