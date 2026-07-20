<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Progress\NullProgressReporter;
use Rouxtaccess\Sync\Types\DbFromS3SyncType;
use Rouxtaccess\Sync\Types\DbOverSshSyncType;
use Rouxtaccess\Sync\Types\FilesOverSshSyncType;
use Rouxtaccess\Sync\Types\S3SyncType;

function progress(): NullProgressReporter
{
    return new NullProgressReporter;
}

/**
 * Every command the fake recorded, one searchable string per process. The fake's
 * assertRan() short-circuits on the first match, so read the recordings directly.
 *
 * @return array<int, string>
 */
function ranCommands(): array
{
    $factory = Process::getFacadeRoot();
    $property = new ReflectionProperty($factory, 'recorded');
    $property->setAccessible(true);

    return array_map(
        fn (array $pair): string => is_array($pair[0]->command) ? implode(' ', $pair[0]->command) : (string) $pair[0]->command,
        $property->getValue($factory),
    );
}

function ranCommand(): string
{
    return implode("\n", ranCommands());
}

function useTempDumps(): void
{
    config()->set('sync.dumps.path', sys_get_temp_dir().'/rouxt-sync-dumps-'.uniqid());
}

it('rsyncs files over ssh with a progress flag', function () {
    Process::fake();

    $result = (new FilesOverSshSyncType)->run(['config' => [
        'ssh' => 'forge@1.2.3.4',
        'remote_path' => '/srv/app/storage/app/public',
        'local_path' => 'storage/app/public',
        'delete' => false,
    ]], false, progress());

    expect($result->ok)->toBeTrue();

    $command = ranCommand();
    expect($command)->toContain('rsync', '-az', '--info=progress2', '-e', 'ssh')
        ->and($command)->toContain('forge@1.2.3.4:/srv/app/storage/app/public/')
        ->and($command)->not->toContain('--delete');
});

it('passes --delete to rsync when requested', function () {
    Process::fake();

    (new FilesOverSshSyncType)->run(['config' => [
        'ssh' => 'forge@1.2.3.4',
        'remote_path' => '/srv/app',
        'local_path' => 'storage',
        'delete' => true,
    ]], false, progress());

    expect(ranCommand())->toContain('--delete');
});

it('builds an aws s3 sync command with profile and delete', function () {
    Process::fake();

    (new S3SyncType)->run(['config' => [
        'source' => 's3://prod-bucket',
        'destination' => 's3://local-bucket',
        'aws_profile' => 'staging',
        'delete' => true,
    ]], false, progress());

    $command = ranCommand();
    expect($command)->toContain('aws', 's3', 'sync', 's3://prod-bucket', 's3://local-bucket', '--delete')
        ->and($command)->toContain('--profile', 'staging');
});

it('reports rsync failure through the SyncResult', function () {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: 'ssh: connect refused', exitCode: 1),
    ]);

    $result = (new FilesOverSshSyncType)->run(['config' => [
        'ssh' => 'forge@1.2.3.4',
        'remote_path' => '/srv/app',
        'local_path' => 'storage',
        'delete' => false,
    ]], false, progress());

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('ssh: connect refused');
});

it('refuses to tunnel to a portless engine such as sqlite', function () {
    Process::fake();

    $result = (new DbOverSshSyncType)->run(['config' => [
        'driver' => 'sqlite',
        'ssh' => 'forge@1.2.3.4',
        'db_host' => '127.0.0.1',
        'db_port' => 0,
        'db_name' => 'app',
        'db_user' => 'app',
        'target_prefix' => 'app',
    ]], false, progress());

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('files-over-ssh');

    Process::assertNothingRan();
});

it('fetches a verbose dump to a file, then imports it through a marker-teeing pipeline', function () {
    useTempDumps();
    Process::fake();

    $result = (new DbOverSshSyncType)->run(['name' => 'db', 'config' => [
        'driver' => 'mysql',
        'ssh' => 'forge@1.2.3.4',
        'db_host' => '127.0.0.1',
        'db_port' => 3306,
        'db_name' => 'app',
        'db_user' => 'app',
        'db_pass' => 'pw',
        'target_prefix' => 'app',
    ]], false, progress());

    expect($result->ok)->toBeTrue();

    $commands = ranCommands();

    // Fetch: mysqldump runs verbosely and is redirected to a dump file.
    expect(collect($commands)->contains(fn (string $c): bool => str_contains($c, 'mysqldump')
        && str_contains($c, '--verbose')
        && str_contains($c, '> ')
        && str_contains($c, '.sql')))->toBeTrue();

    // Load: the dump file is streamed through awk (teeing table markers) into mysql.
    expect(collect($commands)->contains(fn (string $c): bool => str_contains($c, 'awk')
        && str_contains($c, '/dev/stderr')
        && str_contains($c, 'mysql')))->toBeTrue();
});

it('downloads the latest s3 dump to a file before importing', function () {
    useTempDumps();
    Process::fake([
        '*aws s3 ls*' => Process::result(output: 'app-2026-07-16.sql.gz'),
        '*' => Process::result(output: ''),
    ]);

    $result = (new DbFromS3SyncType)->run(['name' => 'db', 'config' => [
        'driver' => 'mysql',
        's3_path' => 's3://my-bucket/mysql/',
        'target_prefix' => 'app',
    ]], false, progress());

    expect($result->ok)->toBeTrue();

    expect(collect(ranCommands())->contains(fn (string $c): bool => str_contains($c, 'aws s3 cp')
        && str_contains($c, 'gunzip')
        && str_contains($c, '> ')))->toBeTrue();
});

it('does not touch production when the local target already exists', function () {
    useTempDumps();

    // Any information_schema lookup reports the target exists; everything else is
    // inert. Non-interactively this must abort before a dump is pulled.
    Process::fake([
        '*information_schema*' => Process::result(output: 'app_2026_07_20'),
        '*' => Process::result(output: ''),
    ]);

    $result = (new DbOverSshSyncType)->run(['name' => 'db', 'config' => [
        'driver' => 'mysql',
        'ssh' => 'forge@1.2.3.4',
        'db_host' => '127.0.0.1',
        'db_port' => 3306,
        'db_name' => 'app',
        'db_user' => 'app',
        'db_pass' => 'pw',
        'target_prefix' => 'app',
    ]], false, progress());

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('already exists');

    expect(collect(ranCommands())->contains(fn (string $c): bool => str_contains($c, 'mysqldump')))->toBeFalse();
});

afterEach(function () {
    if (is_string($path = config('sync.dumps.path')) && str_contains($path, 'rouxt-sync-dumps-')) {
        File::deleteDirectory($path);
    }
});
