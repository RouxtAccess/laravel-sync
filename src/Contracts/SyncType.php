<?php

namespace Rouxtaccess\Sync\Contracts;

use Rouxtaccess\Sync\Field;
use Rouxtaccess\Sync\SyncResult;

interface SyncType
{
    public static function key(): string;

    public static function label(): string;

    /**
     * The fields prompted for when adding a job of this type.
     *
     * @return array<int, Field>
     */
    public function fields(): array;

    /**
     * Rows shown in the confirmation table before running.
     *
     * @param  array<string, mixed>  $job  the full job (name, type, config, after)
     * @return array<int, array{0: string, 1: string}>
     */
    public function summary(array $job): array;

    /**
     * @param  array<string, mixed>  $job  the full job (name, type, config, after); the type's fields live in $job['config']
     * @param  bool  $interactive  Whether the run may prompt the user (false for --yes / no TTY).
     */
    public function run(array $job, bool $interactive): SyncResult;
}
