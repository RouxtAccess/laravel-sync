<?php

namespace Rouxtaccess\Sync\Progress;

use Laravel\Prompts\Progress;
use Rouxtaccess\Sync\Contracts\ProgressReporter;

use function Laravel\Prompts\note;

/**
 * Renders a live progress bar with laravel/prompts. A phase with a known total
 * draws a determinate bar; a phase whose total is not yet known shows a note and
 * upgrades to a bar as soon as setTotal() (or the first known total) arrives.
 */
class PromptsProgressReporter implements ProgressReporter
{
    protected ?Progress $bar = null;

    protected string $label = '';

    protected int $buffered = 0;

    protected string $detail = '';

    public function start(string $label, ?int $total = null): void
    {
        $this->bar = null;
        $this->label = $label;
        $this->buffered = 0;
        $this->detail = '';

        if ($total !== null && $total > 0) {
            $this->openBar($total);

            return;
        }

        note("{$label}…");
    }

    public function setTotal(int $total): void
    {
        if ($this->bar !== null || $total < 1) {
            return;
        }

        $this->openBar($total);
    }

    public function advance(int $step = 1, ?string $detail = null): void
    {
        if ($detail !== null) {
            $this->detail = $detail;
        }

        if ($this->bar === null) {
            $this->buffered += $step;

            return;
        }

        $this->bar->hint($this->detail);
        $this->bar->advance($step);
    }

    public function finish(?string $message = null): void
    {
        $this->bar?->finish();
        $this->bar = null;

        if ($message !== null) {
            note($message);
        }
    }

    protected function openBar(int $total): void
    {
        $this->bar = new Progress($this->label, $total);
        $this->bar->start();

        if ($this->buffered > 0) {
            $this->bar->advance($this->buffered);
            $this->buffered = 0;
        }
    }
}
