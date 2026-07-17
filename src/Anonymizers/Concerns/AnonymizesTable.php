<?php

namespace Rouxtaccess\Sync\Anonymizers\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait AnonymizesTable
{
    /**
     * Overwrite the given columns on every row of a table, one row at a time so
     * a per-row value (derived from the row) can stay unique. Columns that do
     * not exist are skipped, and a missing table or id column is a no-op. This
     * uses the query builder only, so it works on MySQL, PostgreSQL and SQLite.
     *
     * @param  array<int, string>  $candidateColumns
     * @param  Closure(string $column, object $row): mixed  $value
     */
    protected function anonymize(string $connection, string $table, array $candidateColumns, Closure $value): void
    {
        $schema = Schema::connection($connection);

        if (! $schema->hasTable($table)) {
            return;
        }

        $columns = $schema->getColumnListing($table);
        $present = array_values(array_intersect($candidateColumns, $columns));

        if ($present === [] || ! in_array('id', $columns, true)) {
            return;
        }

        DB::connection($connection)
            ->table($table)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($connection, $table, $present, $value): void {
                foreach ($rows as $row) {
                    $update = [];

                    foreach ($present as $column) {
                        $update[$column] = $value($column, $row);
                    }

                    DB::connection($connection)->table($table)->where('id', $row->id)->update($update);
                }
            });
    }
}
