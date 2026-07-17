<?php

namespace Rouxtaccess\Sync\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Rouxtaccess\Sync\SyncServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            SyncServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sync.store', sys_get_temp_dir().'/rouxt-sync-test-'.getmypid().'.json');
    }
}
