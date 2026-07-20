<?php

use Rouxtaccess\Sync\Database\Drivers\MysqlDriver;
use Rouxtaccess\Sync\Database\Drivers\PostgresDriver;
use Rouxtaccess\Sync\Database\Drivers\SqliteDriver;

it('builds a mysqldump command through the tunnel port', function () {
    $command = (new MysqlDriver)->dumpCommand([
        'db_user' => 'forge',
        'db_name' => 'forge',
        'db_pass' => 's3cr3t',
    ], 3307);

    expect($command)->toContain('mysqldump')
        ->toContain('--single-transaction')
        ->toContain('MYSQL_PWD='.escapeshellarg('s3cr3t'))
        ->toContain('-h 127.0.0.1')
        ->toContain('-P '.escapeshellarg('3307'))
        ->toContain('-u '.escapeshellarg('forge'));
});

it('escapes hostile values in the mysqldump command', function () {
    $evil = "app'; rm -rf / #";

    $command = (new MysqlDriver)->dumpCommand([
        'db_user' => 'forge',
        'db_name' => $evil,
        'db_pass' => 'pw',
    ], 3306);

    expect($command)->toContain(escapeshellarg($evil))
        ->and($command)->not->toContain('rm -rf / #"');
});

it('builds a mysql import command for the target database', function () {
    $command = (new MysqlDriver)->importCommand('myapp_2026_07_16');

    expect($command)->toContain('mysql')
        ->toContain('MYSQL_PWD=')
        ->toContain(escapeshellarg('myapp_2026_07_16'));
});

it('strips MySQL DEFINER clauses in the sanitize pipe', function () {
    expect((new MysqlDriver)->sanitizePipe())->toContain('DEFINER');
});

it('builds a pg_dump command without owner or privileges', function () {
    $command = (new PostgresDriver)->dumpCommand([
        'db_user' => 'forge',
        'db_name' => 'forge',
        'db_pass' => 'pw',
    ], 5433);

    expect($command)->toContain('pg_dump')
        ->toContain('--no-owner --no-privileges')
        ->toContain('PGPASSWORD='.escapeshellarg('pw'))
        ->toContain('-p '.escapeshellarg('5433'))
        ->toContain('-U '.escapeshellarg('forge'));
});

it('has no sanitize pipe for postgres', function () {
    expect((new PostgresDriver)->sanitizePipe())->toBeNull();
});

it('builds a sqlite import command and refuses to be dumped over a tunnel', function () {
    $driver = new SqliteDriver;

    expect($driver->importCommand('myapp'))->toContain('sqlite3')
        ->and($driver->importCommand('myapp'))->toContain(escapeshellarg(SqliteDriver::path('myapp')));

    expect(fn () => $driver->dumpCommand([], 0))->toThrow(RuntimeException::class);
});

it('exposes the expected default ports', function () {
    expect((new MysqlDriver)->defaultPort())->toBe(3306)
        ->and((new PostgresDriver)->defaultPort())->toBe(5432)
        ->and((new SqliteDriver)->defaultPort())->toBe(0);
});

it('adds --verbose to the dump command only when asked', function () {
    $remote = ['db_user' => 'forge', 'db_name' => 'forge', 'db_pass' => 'pw'];

    expect((new MysqlDriver)->dumpCommand($remote, 3306))->not->toContain('--verbose')
        ->and((new MysqlDriver)->dumpCommand($remote, 3306, verbose: true))->toContain('--verbose')
        ->and((new PostgresDriver)->dumpCommand($remote, 5432))->not->toContain('--verbose')
        ->and((new PostgresDriver)->dumpCommand($remote, 5432, verbose: true))->toContain('--verbose');
});

it('exposes progress markers for the network engines and none for sqlite', function () {
    $mysql = new MysqlDriver;
    $postgres = new PostgresDriver;
    $sqlite = new SqliteDriver;

    expect($mysql->dumpProgressPattern())->toContain('table structure')
        ->and($mysql->dataMarker())->toContain('Dumping data for table')
        ->and($postgres->dumpProgressPattern())->toContain('dumping contents of table')
        ->and($postgres->dataMarker())->toContain('TABLE DATA');

    expect($sqlite->dumpProgressPattern())->toBeNull()
        ->and($sqlite->dataMarker())->toBeNull()
        ->and($sqlite->countTablesCommand([], 0))->toBeNull();
});

it('counts tables through the tunnel port for the network engines', function () {
    $mysql = (new MysqlDriver)->countTablesCommand(['db_user' => 'forge', 'db_name' => 'forge', 'db_pass' => 'pw'], 3307);
    $postgres = (new PostgresDriver)->countTablesCommand(['db_user' => 'forge', 'db_name' => 'forge', 'db_pass' => 'pw'], 5433);

    expect($mysql)->toContain('information_schema.tables')
        ->toContain('-h 127.0.0.1')
        ->toContain('-P '.escapeshellarg('3307'));

    expect($postgres)->toContain('information_schema.tables')
        ->toContain('BASE TABLE')
        ->toContain('-p '.escapeshellarg('5433'));
});

it('classifies mysqldump verbose comments as noise but keeps real errors', function () {
    $driver = new MysqlDriver;

    expect($driver->isDumpNoise('-- Retrieving table structure for table users...'))->toBeTrue()
        ->and($driver->isDumpNoise('-- Connecting to localhost...'))->toBeTrue()
        ->and($driver->isDumpNoise('mysqldump: Got error: 2013: Lost connection to MySQL server'))->toBeFalse();
});

it('classifies pg_dump verbose lines as noise but keeps errors and warnings', function () {
    $driver = new PostgresDriver;

    expect($driver->isDumpNoise('pg_dump: dumping contents of table "public.users"'))->toBeTrue()
        ->and($driver->isDumpNoise('pg_dump: error: connection to server failed'))->toBeFalse()
        ->and($driver->isDumpNoise('pg_dump: warning: could not resolve dependency'))->toBeFalse()
        ->and($driver->isDumpNoise('FATAL: database "app" does not exist'))->toBeFalse();
});

it('treats no sqlite dump output as noise', function () {
    expect((new SqliteDriver)->isDumpNoise('anything'))->toBeFalse();
});

it('falls back to a mysql-driver connection under a non-conventional name', function () {
    config()->set('database.connections', [
        'primary' => [
            'driver' => 'mysql',
            'host' => '10.0.0.9',
            'port' => 3306,
            'username' => 'bob',
            'password' => 'hunter2',
        ],
    ]);

    $command = (new MysqlDriver)->importCommand('target_db');

    expect($command)->toContain(escapeshellarg('10.0.0.9'))
        ->toContain(escapeshellarg('bob'))
        ->toContain('MYSQL_PWD='.escapeshellarg('hunter2'));
});
