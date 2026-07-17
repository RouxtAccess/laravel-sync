<?php

namespace Rouxtaccess\Sync;

use Rouxtaccess\Sync\Commands\InstallCommand;
use Rouxtaccess\Sync\Commands\RunSyncCommand;
use Rouxtaccess\Sync\Registries\AfterHookRegistry;
use Rouxtaccess\Sync\Registries\DatabaseDriverRegistry;
use Rouxtaccess\Sync\Registries\SyncTypeRegistry;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SyncServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-sync')
            ->hasConfigFile('sync')
            ->hasCommands([
                RunSyncCommand::class,
                InstallCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(
            GroupStore::class,
            fn (): GroupStore => new GroupStore(config('sync.store')),
        );

        $this->app->singleton(
            SyncTypeRegistry::class,
            fn (): SyncTypeRegistry => new SyncTypeRegistry(config('sync.types', [])),
        );

        $this->app->singleton(
            DatabaseDriverRegistry::class,
            fn (): DatabaseDriverRegistry => new DatabaseDriverRegistry(config('sync.database_drivers', [])),
        );

        $this->app->singleton(
            AfterHookRegistry::class,
            fn (): AfterHookRegistry => new AfterHookRegistry(config('sync.after_hooks', [])),
        );
    }
}
