<?php

namespace Rouxtaccess\Sync\Progress;

use Rouxtaccess\Sync\Contracts\ProgressReporter;

/**
 * Reports nothing. The default when a caller does not care about progress
 * (tests, or a non-interactive run that wants silence).
 */
class NullProgressReporter implements ProgressReporter
{
    public function start(string $label, ?int $total = null): void {}

    public function setTotal(int $total): void {}

    public function advance(int $step = 1, ?string $detail = null): void {}

    public function finish(?string $message = null): void {}
}
