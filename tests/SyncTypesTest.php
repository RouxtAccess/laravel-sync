<?php

use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\Types\DbOverSshSyncType;
use Rouxtaccess\Sync\Types\FilesOverSshSyncType;
use Rouxtaccess\Sync\Types\S3SyncType;

function ranCommand(): string
{
    $command = '';

    Process::assertRan(function ($process) use (&$command): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return true;
    });

    return $command;
}

it('rsyncs files over ssh', function () {
    Process::fake();

    $result = (new FilesOverSshSyncType)->run(['config' => [
        'ssh' => 'forge@1.2.3.4',
        'remote_path' => '/srv/app/storage/app/public',
        'local_path' => 'storage/app/public',
        'delete' => false,
    ]], false);

    expect($result->ok)->toBeTrue();

    $command = ranCommand();
    expect($command)->toContain('rsync', '-az', '-e', 'ssh')
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
    ]], false);

    expect(ranCommand())->toContain('--delete');
});

it('builds an aws s3 sync command with profile and delete', function () {
    Process::fake();

    (new S3SyncType)->run(['config' => [
        'source' => 's3://prod-bucket',
        'destination' => 's3://local-bucket',
        'aws_profile' => 'staging',
        'delete' => true,
    ]], false);

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
    ]], false);

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
    ]], false);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('files-over-ssh');

    Process::assertNothingRan();
});
