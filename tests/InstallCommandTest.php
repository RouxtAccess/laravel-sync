<?php

use Illuminate\Support\Facades\File;

it('publishes config, writes a valid-JSON example, and gitignores the store', function () {
    $store = sys_get_temp_dir().'/rouxt-sync-install-'.uniqid().'/sync-jobs.json';
    config()->set('sync.store', $store);
    $example = dirname($store).'/sync-jobs.example.json';

    expect(File::exists($example))->toBeFalse();

    $this->artisan('rouxt:sync-install')->assertExitCode(0);

    expect(File::exists($example))->toBeTrue()
        ->and(json_decode(File::get($example), true))->toBeArray()
        ->and(File::get($example))->toContain('db-over-ssh');

    $gitignore = base_path('.gitignore');
    expect(File::get($gitignore))->toContain(basename($store));

    File::deleteDirectory(dirname($store));
});

it('does not create the live store', function () {
    $store = sys_get_temp_dir().'/rouxt-sync-install-'.uniqid().'/sync-jobs.json';
    config()->set('sync.store', $store);

    $this->artisan('rouxt:sync-install')->assertExitCode(0);

    expect(File::exists($store))->toBeFalse();

    File::deleteDirectory(dirname($store));
});

it('leaves an existing example untouched', function () {
    $store = sys_get_temp_dir().'/rouxt-sync-install-'.uniqid().'/sync-jobs.json';
    config()->set('sync.store', $store);
    $example = dirname($store).'/sync-jobs.example.json';
    File::ensureDirectoryExists(dirname($example));
    File::put($example, '{"kept": true}');

    $this->artisan('rouxt:sync-install')->assertExitCode(0);

    expect(File::get($example))->toBe('{"kept": true}');

    File::deleteDirectory(dirname($store));
});
