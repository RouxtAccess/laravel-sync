<?php

use Rouxtaccess\Sync\Database\Drivers\MysqlDriver;
use Rouxtaccess\Sync\Database\Drivers\PostgresDriver;
use Rouxtaccess\Sync\Database\Drivers\SqliteDriver;
use Rouxtaccess\Sync\Hooks\AnonymizeDatabaseHook;
use Rouxtaccess\Sync\Hooks\RunMigrationsHook;
use Rouxtaccess\Sync\Hooks\SwapEnvDatabaseHook;
use Rouxtaccess\Sync\Types\DbFromS3SyncType;
use Rouxtaccess\Sync\Types\DbOverSshSyncType;
use Rouxtaccess\Sync\Types\FilesOverSshSyncType;
use Rouxtaccess\Sync\Types\S3SyncType;

return [

    /*
     * Where configured groups are persisted, as plain JSON. This file holds each
     * group's jobs and their connection details (passwords included), so it must
     * never be committed; `rouxt:sync-install` gitignores it. Groups are managed from
     * `php artisan rouxt:sync`, and `sync-jobs.example.json` shows the shape.
     */
    'store' => env('SYNC_STORE_PATH', base_path('sync-jobs.json')),

    /*
     * Guards against running a sync in a dangerous environment. A sync only ever
     * creates local databases and copies downward, but the guard is a hard stop
     * for the environments listed below. Running outside them requires --force.
     */
    'guard' => [

        /*
         * Environments in which `rouxt:sync` may run without --force.
         */
        'allowed_environments' => ['local', 'development', 'testing'],
    ],

    /*
     * The available sync types. Each implements Rouxtaccess\Sync\Contracts\SyncType
     * and is offered when adding a job. Register a custom type by appending its
     * class here.
     */
    'types' => [
        DbOverSshSyncType::class,
        DbFromS3SyncType::class,
        FilesOverSshSyncType::class,
        S3SyncType::class,
    ],

    /*
     * The available database engine adaptors for the database sync types. Each
     * implements Rouxtaccess\Sync\Contracts\DatabaseDriver. Add your own by
     * appending a class here.
     */
    'database_drivers' => [
        MysqlDriver::class,
        PostgresDriver::class,
        SqliteDriver::class,
    ],

    /*
     * After-hooks offered once a job succeeds. Each implements
     * Rouxtaccess\Sync\Contracts\AfterHook. They are asked up front (in the same
     * interactive step as conflict handling) and executed at the end of the run,
     * in the order listed here. Register a custom hook by appending its class.
     */
    'after_hooks' => [
        SwapEnvDatabaseHook::class,
        RunMigrationsHook::class,
        AnonymizeDatabaseHook::class,
    ],

    /*
     * Anonymizers run by the AnonymizeDatabaseHook against a freshly imported
     * database. Each entry is either a raw SQL statement (string) or the class
     * name of an invokable action resolved from the container and called with the
     * connection name: (new Action)($connection).
     *
     * This ships empty (anonymization is opt-in). The package includes a couple
     * of ready-to-use, driver-portable examples you can enable, and you can add
     * your own raw SQL or invokable classes alongside them:
     *
     *   use Rouxtaccess\Sync\Anonymizers\AnonymizeUserEmails;
     *   use Rouxtaccess\Sync\Anonymizers\AnonymizeUserPhoneNumbers;
     *
     *   'anonymizers' => [
     *       AnonymizeUserEmails::class,        // users.email -> user{id}@example.test
     *       AnonymizeUserPhoneNumbers::class,  // users.phone_number / msisdn / etc.
     *       "UPDATE users SET password = '', remember_token = NULL",
     *       App\Sync\Anonymizers\ScrubPaymentTokens::class,
     *   ],
     */
    'anonymizers' => [],

];
