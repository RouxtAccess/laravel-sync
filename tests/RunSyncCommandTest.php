<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Rouxtaccess\Sync\GroupStore;

function seedFilesGroup(): void
{
    app(GroupStore::class)->put('production', [
        [
            'name' => 'storage',
            'type' => 'files-over-ssh',
            'config' => [
                'ssh' => 'forge@1.2.3.4',
                'remote_path' => '/srv/app/storage',
                'local_path' => 'storage/app',
                'delete' => false,
            ],
        ],
    ]);
}

it('refuses to run outside the allowed environments without --force', function () {
    config()->set('sync.guard.allowed_environments', ['local']);
    seedFilesGroup();
    Process::fake();

    $this->artisan('rouxt:sync', ['group' => 'production', '--yes' => true])
        ->assertExitCode(1);

    Process::assertNothingRan();
});

it('runs in a disallowed environment when --force is passed', function () {
    config()->set('sync.guard.allowed_environments', ['local']);
    seedFilesGroup();
    Process::fake();

    $this->artisan('rouxt:sync', ['group' => 'production', '--yes' => true, '--force' => true])
        ->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'rsync');
    });
});

it('runs the job when the environment is allowed', function () {
    config()->set('sync.guard.allowed_environments', ['testing']);
    seedFilesGroup();
    Process::fake();

    $this->artisan('rouxt:sync', ['group' => 'production', '--yes' => true])
        ->assertExitCode(0);
});

it('fails for an unknown group', function () {
    config()->set('sync.guard.allowed_environments', ['testing']);
    Process::fake();

    $this->artisan('rouxt:sync', ['group' => 'nope', '--yes' => true])
        ->assertExitCode(1);
});

it('upgrades an old flat-format store when run', function () {
    config()->set('sync.guard.allowed_environments', ['testing']);
    $store = config('sync.store');
    File::put($store, json_encode([
        'production' => ['jobs' => [[
            'name' => 'storage',
            'type' => 'files-over-ssh',
            'ssh' => 'forge@1.2.3.4',
            'remote_path' => '/srv/app/storage',
            'local_path' => 'storage/app',
            'delete' => false,
        ]]],
    ]));
    Process::fake();

    $this->artisan('rouxt:sync', ['group' => 'production', '--yes' => true])
        ->assertExitCode(0);

    $raw = json_decode(File::get($store), true);
    expect($raw['production']['jobs'][0])->toHaveKey('config')
        ->and($raw['production']['jobs'][0])->not->toHaveKey('ssh')
        ->and($raw['production']['jobs'][0]['config']['ssh'])->toBe('forge@1.2.3.4');
});
