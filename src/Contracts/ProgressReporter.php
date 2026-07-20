<?php

namespace Rouxtaccess\Sync\Contracts;

interface ProgressReporter
{
    /**
     * Begin a phase. Pass a known total to draw a determinate bar, or null when
     * the size is not yet known (the phase renders as indeterminate until a
     * later setTotal() upgrades it).
     */
    public function start(string $label, ?int $total = null): void;

    /**
     * Set (or upgrade to) a known total once it becomes available mid-phase.
     */
    public function setTotal(int $total): void;

    /**
     * Advance the current phase by $step, optionally replacing the detail line
     * shown alongside the bar (e.g. the table currently importing).
     */
    public function advance(int $step = 1, ?string $detail = null): void;

    /**
     * Finish the current phase, optionally printing a closing message.
     */
    public function finish(?string $message = null): void;
}
