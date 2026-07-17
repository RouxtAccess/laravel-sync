<?php

namespace Rouxtaccess\Sync\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use Rouxtaccess\Sync\Database\Drivers\SqliteDriver;

trait ConnectsToImportedDatabase
{
    /**
     * Point the job's connection at the freshly imported database and run the
     * callback against that connection name. The connection defaults to the
     * driver key (mysql, pgsql, sqlite), matching a stock Laravel app; a job may
     * override it with a `connection` key.
     *
     * @param  array<string, mixed>  $job
     * @param  array<string, mixed>  $context
     */
    protected function onImportedDatabase(array $job, array $context, Closure $callback): mixed
    {
        $connection = data_get($job, 'config.connection') ?? data_get($job, 'config.driver') ?? 'mysql';
        $database = $context['database'];

        if ($connection === SqliteDriver::key()) {
            $database = SqliteDriver::path($database);
        }

        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);

        return $callback($connection);
    }
}
