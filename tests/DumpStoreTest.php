<?php

use Illuminate\Support\Facades\File;
use Rouxtaccess\Sync\Database\DumpStore;

function dumpDir(): string
{
    return sys_get_temp_dir().'/rouxt-dumpstore-'.getmypid();
}

function store(int $keep = 3): DumpStore
{
    return new DumpStore(dumpDir(), $keep);
}

function seedDump(string $job, string $timestamp): string
{
    $path = store()->pathFor($job, $timestamp);
    File::put($path, '-- dump');

    return $path;
}

beforeEach(fn () => File::deleteDirectory(dumpDir()));
afterEach(fn () => File::deleteDirectory(dumpDir()));

it('builds a slugged, timestamped path and creates the directory on demand', function () {
    $path = store()->pathFor('My Job', '2026_07_20_101500');

    expect(File::isDirectory(dumpDir()))->toBeTrue()
        ->and(basename($path))->toBe('my-job-2026_07_20_101500.sql');
});

it('returns dumps newest first and exposes the latest', function () {
    seedDump('db', '2026_07_18_100000');
    $newest = seedDump('db', '2026_07_20_100000');
    seedDump('db', '2026_07_19_100000');

    expect(store()->all('db'))->toHaveCount(3)
        ->and(store()->latest('db'))->toBe($newest);
});

it('does not let one job glob another job whose slug shares its prefix', function () {
    $own = seedDump('db', '2026_07_20_100000');
    seedDump('db-analytics', '2026_07_20_100000');

    // Without an anchored timestamp segment, "db" would also match "db-analytics-…".
    expect(store()->all('db'))->toBe([$own])
        ->and(store()->all('db-analytics'))->toHaveCount(1);
});

it('prunes all but the newest keep dumps and never touches another job', function () {
    foreach (['2026_07_17', '2026_07_18', '2026_07_19', '2026_07_20'] as $day) {
        seedDump('db', $day.'_100000');
    }
    $other = seedDump('db-analytics', '2026_07_20_100000');

    store(keep: 2)->prune('db');

    expect(store()->all('db'))->toHaveCount(2)
        ->and(store()->latest('db'))->toContain('2026_07_20')
        ->and(File::exists($other))->toBeTrue();
});

it('reports nothing for a missing directory', function () {
    $missing = new DumpStore(dumpDir().'/nope', 3);

    expect($missing->all('db'))->toBe([])
        ->and($missing->latest('db'))->toBeNull();
});
