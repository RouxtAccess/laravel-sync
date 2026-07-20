<?php

namespace Rouxtaccess\Sync\Progress;

use Closure;
use Rouxtaccess\Sync\Contracts\ProgressReporter;

/**
 * Plain, append-only progress for non-interactive runs (--yes or no TTY), where
 * a redrawing bar is not appropriate. Emits a modest number of lines: the start
 * of each phase, throttled updates, and a closing line.
 */
class LineProgressReporter implements ProgressReporter
{
    protected string $label = '';

    protected ?int $total = null;

    protected int $current = 0;

    protected int $lastBucket = -1;

    /**
     * @param  Closure(string): void|null  $writer  where lines go (defaults to stdout)
     */
    public function __construct(protected ?Closure $writer = null) {}

    public function start(string $label, ?int $total = null): void
    {
        $this->label = $label;
        $this->total = $total;
        $this->current = 0;
        $this->lastBucket = -1;

        $this->write($total !== null ? "{$label} (0/{$total})" : "{$label}…");
    }

    public function setTotal(int $total): void
    {
        $this->total = $total;
    }

    public function advance(int $step = 1, ?string $detail = null): void
    {
        $this->current += $step;

        if (! $this->shouldEmit()) {
            return;
        }

        $suffix = $detail !== null ? " — {$detail}" : '';

        $this->write($this->total !== null
            ? "  {$this->current}/{$this->total}{$suffix}"
            : "  {$this->current}{$suffix}");
    }

    public function finish(?string $message = null): void
    {
        if ($message !== null) {
            $this->write($message);
        }
    }

    /**
     * Print every step for small totals (each table is worth a line); for large
     * or unknown totals, throttle to roughly one line per five percent.
     */
    protected function shouldEmit(): bool
    {
        if ($this->total === null || $this->total <= 50) {
            return true;
        }

        $bucket = (int) floor($this->current / $this->total * 20);

        if ($bucket === $this->lastBucket) {
            return false;
        }

        $this->lastBucket = $bucket;

        return true;
    }

    protected function write(string $message): void
    {
        if ($this->writer !== null) {
            ($this->writer)($message);

            return;
        }

        fwrite(STDOUT, $message.PHP_EOL);
    }
}
