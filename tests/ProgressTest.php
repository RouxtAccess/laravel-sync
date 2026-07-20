<?php

use Laravel\Prompts\Progress;
use Laravel\Prompts\Prompt;
use Rouxtaccess\Sync\Concerns\StreamsProcessProgress;
use Rouxtaccess\Sync\Progress\LineProgressReporter;
use Rouxtaccess\Sync\Progress\PromptsProgressReporter;

/**
 * Read the reporter's live bar (a protected property) so its real state can be
 * asserted.
 */
function activeBar(PromptsProgressReporter $reporter): ?Progress
{
    $property = new ReflectionProperty($reporter, 'bar');
    $property->setAccessible(true);

    return $property->getValue($reporter);
}

/**
 * Exposes streamProcess() so the line-splitting and end-of-stream flush can be
 * driven with real, deterministic commands (Process::fake never invokes the
 * output callback, so a fake cannot exercise this code).
 */
class StreamHarness
{
    use StreamsProcessProgress;

    /**
     * @return array<int, string>
     */
    public function collect(array|string $command): array
    {
        $lines = [];

        $this->streamProcess($command, function (string $stream, string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return $lines;
    }
}

it('records every step for a small total', function () {
    $lines = [];
    $reporter = new LineProgressReporter(function (string $m) use (&$lines) {
        $lines[] = $m;
    });

    $reporter->start('Importing', 3);
    $reporter->advance(1, 'a');
    $reporter->advance(1, 'b');
    $reporter->advance(1, 'c');
    $reporter->finish('done');

    expect($lines)->toHaveCount(5)
        ->and($lines[0])->toContain('0/3')
        ->and($lines[1])->toContain('1/3')->toContain('a')
        ->and($lines[4])->toBe('done');
});

it('throttles a large total to roughly one line per five percent', function () {
    $lines = [];
    $reporter = new LineProgressReporter(function (string $m) use (&$lines) {
        $lines[] = $m;
    });

    $reporter->start('Importing', 100);

    for ($i = 0; $i < 100; $i++) {
        $reporter->advance();
    }

    // Start line plus about twenty buckets, never one line per step.
    expect(count($lines))->toBeLessThanOrEqual(22)
        ->and(count($lines))->toBeGreaterThan(10);
});

it('emits every advance when the total is unknown', function () {
    $lines = [];
    $reporter = new LineProgressReporter(function (string $m) use (&$lines) {
        $lines[] = $m;
    });

    $reporter->start('Dumping');
    $reporter->advance();
    $reporter->advance();

    expect($lines)->toHaveCount(3)
        ->and($lines[0])->toBe('Dumping…');
});

it('delivers a final line that has no trailing newline', function () {
    expect((new StreamHarness)->collect(['bash', '-c', 'printf "a\nb"']))->toBe(['a', 'b']);
});

it('does not emit a phantom empty line for a trailing newline', function () {
    expect((new StreamHarness)->collect(['bash', '-c', 'printf "a\nb\n"']))->toBe(['a', 'b']);
});

it('splits carriage-return progress output as separate lines', function () {
    expect((new StreamHarness)->collect(['bash', '-c', 'printf "x\ry\r"']))->toBe(['x', 'y']);
});

it('draws a determinate bar when the total is known up front', function () {
    Prompt::fake();
    $reporter = new PromptsProgressReporter;

    $reporter->start('Importing', 3);
    $reporter->advance(1, 'users');

    $bar = activeBar($reporter);
    expect($bar)->not->toBeNull()
        ->and($bar->total)->toBe(3)
        ->and($bar->progress)->toBe(1)
        ->and($bar->hint)->toBe('users');

    $reporter->finish('done');
    expect(activeBar($reporter))->toBeNull();
});

it('buffers advances made before the total is known and flushes them when the bar opens', function () {
    Prompt::fake();
    $reporter = new PromptsProgressReporter;

    // Unknown total: no bar yet, advances accumulate.
    $reporter->start('Dumping');
    expect(activeBar($reporter))->toBeNull();

    $reporter->advance(3, 'partway');
    $reporter->setTotal(10);

    $bar = activeBar($reporter);
    expect($bar)->not->toBeNull()
        ->and($bar->total)->toBe(10)
        ->and($bar->progress)->toBe(3);

    $reporter->advance(2);
    expect(activeBar($reporter)->progress)->toBe(5);
});

it('ignores a non-positive total and never overshoots the bar', function () {
    Prompt::fake();
    $reporter = new PromptsProgressReporter;

    // A zero total leaves the phase indeterminate rather than opening an empty bar.
    $reporter->start('Dumping', 0);
    expect(activeBar($reporter))->toBeNull();

    // Once a real total is set, advancing past it is capped, not overrun.
    $reporter->setTotal(2);
    $reporter->advance(5);
    expect(activeBar($reporter)->progress)->toBe(2);
});
