<?php

use Illuminate\Support\Facades\File;
use Rouxtaccess\Sync\Hooks\AnonymizeDatabaseHook;
use Rouxtaccess\Sync\Hooks\SwapEnvDatabaseHook;

it('applies the swap-env hook only to database jobs', function () {
    $hook = new SwapEnvDatabaseHook;

    expect($hook->appliesToJob(['type' => 'db-over-ssh']))->toBeTrue()
        ->and($hook->appliesToJob(['type' => 'db-from-s3']))->toBeTrue()
        ->and($hook->appliesToJob(['type' => 'files-over-ssh']))->toBeFalse();
});

it('does not prompt when non-interactive', function () {
    expect((new SwapEnvDatabaseHook)->prompt([], ['database' => 'x'], false))->toBeFalse();
});

it('rewrites an existing DB_DATABASE line', function () {
    $dir = sys_get_temp_dir().'/rouxt-sync-env-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put($dir.'/.env', "APP_ENV=local\nDB_DATABASE=old_db\nDB_USERNAME=root\n");

    $this->app->useEnvironmentPath($dir);

    $message = (new SwapEnvDatabaseHook)->handle(['type' => 'db-over-ssh'], ['database' => 'new_db']);

    expect(File::get($dir.'/.env'))->toContain('DB_DATABASE=new_db')
        ->and(File::get($dir.'/.env'))->not->toContain('old_db')
        ->and($message)->toContain('new_db');

    File::deleteDirectory($dir);
});

it('appends DB_DATABASE when it is missing', function () {
    $dir = sys_get_temp_dir().'/rouxt-sync-env-'.uniqid();
    File::ensureDirectoryExists($dir);
    File::put($dir.'/.env', "APP_ENV=local\n");

    $this->app->useEnvironmentPath($dir);

    (new SwapEnvDatabaseHook)->handle(['type' => 'db-over-ssh'], ['database' => 'fresh_db']);

    expect(File::get($dir.'/.env'))->toContain('DB_DATABASE=fresh_db');

    File::deleteDirectory($dir);
});

it('only offers the anonymize hook when anonymizers are configured', function () {
    config()->set('sync.anonymizers', []);
    expect((new AnonymizeDatabaseHook)->appliesToJob(['type' => 'db-over-ssh']))->toBeFalse();

    config()->set('sync.anonymizers', ["UPDATE users SET email = 'x'"]);
    expect((new AnonymizeDatabaseHook)->appliesToJob(['type' => 'db-over-ssh']))->toBeTrue();
});
