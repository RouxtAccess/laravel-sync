<?php

use Illuminate\Support\Facades\File;
use Rouxtaccess\Sync\GroupStore;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/rouxt-sync-store-'.uniqid().'.json';
    $this->store = new GroupStore($this->path);
});

afterEach(function () {
    File::delete($this->path);
});

it('returns an empty collection when the store is missing', function () {
    expect($this->store->all()->all())->toBe([])
        ->and($this->store->names())->toBe([])
        ->and($this->store->has('production'))->toBeFalse()
        ->and($this->store->jobs('production'))->toBe([]);
});

it('persists and reads back a group', function () {
    $this->store->put('production', [
        ['name' => 'db', 'type' => 'db-over-ssh', 'config' => ['driver' => 'mysql']],
    ]);

    expect($this->store->has('production'))->toBeTrue()
        ->and($this->store->names())->toBe(['production'])
        ->and($this->store->jobs('production'))->toBe([
            ['name' => 'db', 'type' => 'db-over-ssh', 'config' => ['driver' => 'mysql']],
        ]);
});

it('writes valid, pretty-printed JSON', function () {
    $this->store->put('production', [
        ['name' => 'db', 'type' => 'files-over-ssh', 'config' => ['remote_path' => 'http://example.test//path']],
    ]);

    $contents = File::get($this->path);

    expect(json_decode($contents, true))->toBeArray()
        ->and($contents)->toContain("\n")
        ->and($this->store->jobs('production')[0]['config']['remote_path'])->toBe('http://example.test//path');
});

it('tolerates a malformed store', function () {
    File::put($this->path, '{ not json ');

    expect($this->store->all()->all())->toBe([]);
});

function writeFlatStore(string $path): void
{
    File::put($path, json_encode([
        'legacy' => [
            'jobs' => [
                [
                    'name' => 'db',
                    'type' => 'db-over-ssh',
                    'driver' => 'mysql',
                    'ssh' => 'forge@1.2.3.4',
                    'db_name' => 'forge',
                    'db_pass' => 'secret',
                    'after' => ['swap-env-database'],
                ],
            ],
        ],
    ]));
}

it('reads an old flat job as the nested shape without losing data', function () {
    writeFlatStore($this->path);

    $job = $this->store->jobs('legacy')[0];

    expect($job)->toHaveKeys(['name', 'type', 'config', 'after'])
        ->and($job)->not->toHaveKey('driver')
        ->and($job['name'])->toBe('db')
        ->and($job['type'])->toBe('db-over-ssh')
        ->and($job['after'])->toBe(['swap-env-database'])
        ->and($job['config'])->toBe([
            'driver' => 'mysql',
            'ssh' => 'forge@1.2.3.4',
            'db_name' => 'forge',
            'db_pass' => 'secret',
        ]);
});

it('migrates an old flat store on disk and is idempotent', function () {
    writeFlatStore($this->path);

    expect($this->store->migrate())->toBe(1);

    $raw = json_decode(File::get($this->path), true);
    expect($raw['legacy']['jobs'][0])->toHaveKey('config')
        ->and($raw['legacy']['jobs'][0])->not->toHaveKey('driver')
        ->and($raw['legacy']['jobs'][0]['config']['db_pass'])->toBe('secret');

    expect($this->store->migrate())->toBe(0);
});

it('leaves an already-nested store untouched when migrating', function () {
    $this->store->put('current', [
        ['name' => 'db', 'type' => 'db-over-ssh', 'config' => ['driver' => 'mysql']],
    ]);

    expect($this->store->migrate())->toBe(0);
});

it('normalizes a flat job passed to put', function () {
    $this->store->put('production', [
        ['name' => 'db', 'type' => 'db-over-ssh', 'driver' => 'mysql', 'after' => ['swap-env-database']],
    ]);

    $job = $this->store->jobs('production')[0];

    expect($job['config'])->toBe(['driver' => 'mysql'])
        ->and($job['after'])->toBe(['swap-env-database'])
        ->and($job)->not->toHaveKey('driver');
});

it('reports nothing to migrate when the store is missing', function () {
    expect($this->store->migrate())->toBe(0);
});
