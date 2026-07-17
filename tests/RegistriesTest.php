<?php

use Rouxtaccess\Sync\Database\Drivers\MysqlDriver;
use Rouxtaccess\Sync\Database\Drivers\PostgresDriver;
use Rouxtaccess\Sync\Database\Drivers\SqliteDriver;
use Rouxtaccess\Sync\Hooks\SwapEnvDatabaseHook;
use Rouxtaccess\Sync\Registries\AfterHookRegistry;
use Rouxtaccess\Sync\Registries\DatabaseDriverRegistry;
use Rouxtaccess\Sync\Registries\SyncTypeRegistry;
use Rouxtaccess\Sync\Types\DbOverSshSyncType;

it('resolves registered sync types by key', function () {
    $registry = app(SyncTypeRegistry::class);

    expect($registry->has('db-over-ssh'))->toBeTrue()
        ->and($registry->get('db-over-ssh'))->toBeInstanceOf(DbOverSshSyncType::class)
        ->and($registry->options())->toHaveKeys(['db-over-ssh', 'db-from-s3', 'files-over-ssh', 's3-sync']);
});

it('throws on an unknown sync type', function () {
    app(SyncTypeRegistry::class)->get('nope');
})->throws(InvalidArgumentException::class, 'Unknown sync type [nope].');

it('resolves registered database drivers including sqlite', function () {
    $registry = app(DatabaseDriverRegistry::class);

    expect($registry->get('mysql'))->toBeInstanceOf(MysqlDriver::class)
        ->and($registry->get('pgsql'))->toBeInstanceOf(PostgresDriver::class)
        ->and($registry->get('sqlite'))->toBeInstanceOf(SqliteDriver::class);
});

it('throws on an unknown database driver', function () {
    app(DatabaseDriverRegistry::class)->get('nope');
})->throws(InvalidArgumentException::class, 'Unknown database driver [nope].');

it('lists after-hooks applicable to a db job', function () {
    $applicable = app(AfterHookRegistry::class)->applicableTo(['type' => 'db-over-ssh']);

    expect($applicable)->toHaveKey(SwapEnvDatabaseHook::key());
});

it('excludes db-only hooks from a files job', function () {
    $applicable = app(AfterHookRegistry::class)->applicableTo(['type' => 'files-over-ssh']);

    expect($applicable)->not->toHaveKey(SwapEnvDatabaseHook::key());
});
