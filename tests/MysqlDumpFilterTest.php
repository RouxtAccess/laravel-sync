<?php

use Carbon\CarbonImmutable;
use Rouxtaccess\Sync\Database\MysqlDumpFilter;

function filterDumpString(string $dump, array $filters, ?array &$stats = null): string
{
    $source = tempnam(sys_get_temp_dir(), 'dump-in-');
    $destination = tempnam(sys_get_temp_dir(), 'dump-out-');
    file_put_contents($source, $dump);

    $stats = (new MysqlDumpFilter($filters, CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC')))
        ->filter($source, $destination);

    $filtered = (string) file_get_contents($destination);
    @unlink($source);
    @unlink($destination);

    return $filtered;
}

function sampleDump(): string
{
    return <<<'SQL'
-- MySQL dump 10.13
CREATE TABLE `logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message` text NOT NULL,
  `payload` blob,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

--
-- Dumping data for table `logs`
--

INSERT INTO `logs` VALUES (1,'old, (tricky) \'one\'),(',_binary 'a,b)','2026-08-01 00:00:00'),(2,'fresh \\',NULL,'2026-10-19 08:00:00'),(3,'no date',NULL,NULL);
INSERT INTO `logs` VALUES (4,'all old',NULL,'2026-01-01 00:00:00');
CREATE TABLE `batches` (
  `id` varchar(255) NOT NULL,
  `created_at` int NOT NULL
) ENGINE=InnoDB;
INSERT INTO `batches` VALUES ('a',1760000000),('b',1792400000);
CREATE TABLE `pulse` (
  `timestamp` int unsigned NOT NULL,
  `key_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`key`))) VIRTUAL,
  `key` text NOT NULL
) ENGINE=InnoDB;
INSERT INTO `pulse` (`timestamp`, `key`) VALUES (1792400000,'new'),(1700000000,'old');
CREATE TABLE `telescope_entries` (
  `id` bigint NOT NULL
) ENGINE=InnoDB;
INSERT INTO `telescope_entries` VALUES (1),(2),(3);
CREATE TABLE `users` (
  `id` bigint NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB;
INSERT INTO `users` VALUES (1,'2020-01-01 00:00:00');

SQL;
}

it('keeps only recent rows, skips whole tables, and leaves unfiltered tables untouched', function () {
    $filtered = filterDumpString(sampleDump(), [
        'logs' => ['column' => 'created_at', 'days' => 28],
        'batches' => ['column' => 'created_at', 'days' => 28],
        'pulse' => ['column' => 'timestamp', 'days' => 28],
        'telescope_entries' => ['skip' => true],
    ], $stats);

    expect($filtered)
        ->toContain("INSERT INTO `logs` VALUES (2,'fresh \\\\',NULL,'2026-10-19 08:00:00');\n")
        ->not->toContain('all old')
        ->not->toContain('no date')
        ->toContain("INSERT INTO `batches` VALUES ('b',1792400000);\n")
        ->toContain("INSERT INTO `pulse` (`timestamp`, `key`) VALUES (1792400000,'new');\n")
        ->not->toContain('INSERT INTO `telescope_entries`')
        ->toContain('CREATE TABLE `telescope_entries`')
        ->toContain("INSERT INTO `users` VALUES (1,'2020-01-01 00:00:00');\n")
        ->toContain('-- Dumping data for table `logs`');

    expect($stats)->toBe([
        'logs' => ['kept' => 1, 'dropped' => 3],
        'batches' => ['kept' => 1, 'dropped' => 1],
        'pulse' => ['kept' => 1, 'dropped' => 1],
        'telescope_entries' => ['kept' => 0, 'dropped' => 3],
    ]);
});

it('fails loudly when the filter column does not exist', function () {
    filterDumpString(sampleDump(), ['logs' => ['column' => 'missing_at', 'days' => 7]]);
})->throws(RuntimeException::class, 'Column missing_at not found on table logs.');

it('validates the filters block', function (mixed $filters, ?string $problem) {
    expect(MysqlDumpFilter::validate($filters))->toBe($problem);
})->with([
    'valid' => [['logs' => ['column' => 'created_at', 'days' => 28], 'telescope_entries' => ['skip' => true]], null],
    'not an object' => ['logs', 'filters must be an object keyed by table name.'],
    'no column' => [['logs' => ['days' => 28]], 'Filter for logs needs either "skip": true or a "column".'],
    'bad days' => [['logs' => ['column' => 'created_at', 'days' => '28']], 'Filter for logs needs "days" as a positive whole number.'],
    'scalar filter' => [['logs' => 'skip'], 'Filter for logs must be an object.'],
]);
