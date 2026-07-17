<?php

use Rouxtaccess\Sync\Database\Drivers\SqliteDriver;

it('maps a bare name into the database directory', function () {
    expect(SqliteDriver::path('myapp_2026_07_16'))->toBe(database_path('myapp_2026_07_16.sqlite'));
});

it('keeps an explicit path or file untouched', function () {
    expect(SqliteDriver::path('/abs/data.sqlite'))->toBe('/abs/data.sqlite')
        ->and(SqliteDriver::path('nested/data.db'))->toBe('nested/data.db');
});

it('has no network port', function () {
    expect((new SqliteDriver)->defaultPort())->toBe(0);
});

it('reports existence from the resolved file path', function () {
    $driver = new SqliteDriver;
    $name = 'rouxt-sync-sqlite-'.uniqid();
    $path = SqliteDriver::path($name);

    expect($driver->databaseExists($name))->toBeFalse();

    touch($path);
    expect($driver->databaseExists($name))->toBeTrue();

    unlink($path);
});
