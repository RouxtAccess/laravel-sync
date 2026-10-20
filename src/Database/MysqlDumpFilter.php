<?php

namespace Rouxtaccess\Sync\Database;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Rewrites a mysqldump file so heavy tables arrive trimmed. Per table, a filter
 * either skips the data entirely (the table is still created, empty) or keeps
 * only the rows whose date column falls within the last N days. Rows are
 * dropped from the dump before the import, so the local database never pays
 * for history nobody looks at.
 *
 * The filter streams the file line by line. It relies on the mysqldump layout:
 * one `CREATE TABLE` per table with one column per line, and every extended
 * `INSERT` on a single line with strings single quoted and backslash escaped.
 */
class MysqlDumpFilter
{
    /** @var array<string, array{skip: bool, column: ?string, cutoff: ?string, cutoffTimestamp: ?int}> */
    protected array $rules = [];

    /** @var array<string, array<int, string>> */
    protected array $columns = [];

    /** @var array<string, array{kept: int, dropped: int}> */
    protected array $stats = [];

    /**
     * @param  array<string, mixed>  $filters  table => ['skip' => true] or ['column' => 'created_at', 'days' => 28]
     */
    public function __construct(array $filters, ?CarbonImmutable $now = null)
    {
        $now ??= CarbonImmutable::now('UTC');

        foreach ($filters as $table => $filter) {
            $this->rules[(string) $table] = $this->rule((string) $table, $filter, $now);
        }
    }

    /**
     * Validate a job's `filters` block without filtering anything. Returns the
     * problem, or null when the block is usable.
     */
    public static function validate(mixed $filters): ?string
    {
        if (! is_array($filters)) {
            return 'filters must be an object keyed by table name.';
        }

        try {
            new self($filters);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Filter $source into $destination. Calls $onTable with each table name as
     * its data section starts, so a caller can drive a progress bar.
     *
     * @param  (callable(string): void)|null  $onTable
     * @return array<string, array{kept: int, dropped: int}> rows kept and dropped per filtered table
     */
    public function filter(string $source, string $destination, ?callable $onTable = null): array
    {
        $in = @fopen($source, 'rb');
        $out = @fopen($destination, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException("Could not open {$source} for filtering.");
        }

        $creating = null;

        try {
            while (($line = fgets($in)) !== false) {
                if ($creating !== null) {
                    $creating = $this->collectColumn($creating, $line);
                    fwrite($out, $line);

                    continue;
                }

                if (str_starts_with($line, 'CREATE TABLE `')) {
                    $creating = $this->tableName($line, 'CREATE TABLE ');
                    $this->columns[$creating] = [];
                    fwrite($out, $line);

                    continue;
                }

                if ($onTable !== null && str_starts_with($line, '-- Dumping data for table `')) {
                    $onTable($this->tableName($line, '-- Dumping data for table '));
                }

                if (! str_starts_with($line, 'INSERT INTO `')) {
                    fwrite($out, $line);

                    continue;
                }

                $filtered = $this->filterInsert($line);

                if ($filtered !== null) {
                    fwrite($out, $filtered);
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $this->stats;
    }

    /**
     * @return array{skip: bool, column: ?string, cutoff: ?string, cutoffTimestamp: ?int}
     */
    protected function rule(string $table, mixed $filter, CarbonImmutable $now): array
    {
        if (! is_array($filter)) {
            throw new InvalidArgumentException("Filter for {$table} must be an object.");
        }

        if (($filter['skip'] ?? false) === true) {
            return ['skip' => true, 'column' => null, 'cutoff' => null, 'cutoffTimestamp' => null];
        }

        $column = $filter['column'] ?? null;
        $days = $filter['days'] ?? null;

        if (! is_string($column) || $column === '') {
            throw new InvalidArgumentException("Filter for {$table} needs either \"skip\": true or a \"column\".");
        }

        if (! is_int($days) || $days < 1) {
            throw new InvalidArgumentException("Filter for {$table} needs \"days\" as a positive whole number.");
        }

        $cutoff = $now->subDays($days);

        return [
            'skip' => false,
            'column' => $column,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'cutoffTimestamp' => $cutoff->getTimestamp(),
        ];
    }

    /**
     * Record one column line of a CREATE TABLE. Returns the table still being
     * read, or null once its definition closes.
     */
    protected function collectColumn(string $table, string $line): ?string
    {
        if (str_starts_with($line, ')')) {
            return null;
        }

        if (preg_match('/^\s+`((?:[^`]|``)+)`\s/', $line, $matches) === 1) {
            $this->columns[$table][] = str_replace('``', '`', $matches[1]);
        }

        return $table;
    }

    protected function tableName(string $line, string $prefix): string
    {
        $rest = substr($line, strlen($prefix) + 1);

        return str_replace('``', '`', substr($rest, 0, (int) strpos($rest, '`')));
    }

    /**
     * Apply the table's rule to one extended INSERT line. Returns the line to
     * write, or null when no row survives.
     */
    protected function filterInsert(string $line): ?string
    {
        $table = $this->tableName($line, 'INSERT INTO ');
        $rule = $this->rules[$table] ?? null;

        if ($rule === null) {
            return $line;
        }

        $this->stats[$table] ??= ['kept' => 0, 'dropped' => 0];

        $valuesAt = strpos($line, ' VALUES ');

        if ($valuesAt === false) {
            throw new RuntimeException("Unrecognised INSERT for {$table}.");
        }

        $head = substr($line, 0, $valuesAt);
        $index = $rule['skip'] ? 0 : $this->columnIndex($table, (string) $rule['column'], $head);

        if (preg_match_all($this->tuplePattern($index), $line, $tuples, PREG_SET_ORDER, $valuesAt) === false) {
            throw new RuntimeException("Could not parse the rows of {$table}: ".preg_last_error_msg());
        }

        if ($rule['skip']) {
            $this->stats[$table]['dropped'] += count($tuples);

            return null;
        }

        $kept = [];

        foreach ($tuples as $tuple) {
            if (! $this->isRecent($this->fieldValue($tuple[1]), $rule)) {
                $this->stats[$table]['dropped']++;

                continue;
            }

            $kept[] = $tuple[0];
            $this->stats[$table]['kept']++;
        }

        if ($kept === []) {
            return null;
        }

        return $head.' VALUES '.implode(',', $kept).";\n";
    }

    /**
     * Position of the filter column, from the INSERT's own column list when
     * mysqldump wrote one (it does for tables with generated columns),
     * otherwise from the CREATE TABLE.
     */
    protected function columnIndex(string $table, string $column, string $head): int
    {
        $columns = $this->columns[$table] ?? [];
        $list = strpos($head, ' (');

        if ($list !== false) {
            preg_match_all('/`((?:[^`]|``)+)`/', substr($head, $list), $matches);
            $columns = array_map(fn (string $name): string => str_replace('``', '`', $name), $matches[1]);
        }

        $index = array_search($column, $columns, true);

        if ($index === false) {
            throw new RuntimeException("Column {$column} not found on table {$table}.");
        }

        return (int) $index;
    }

    /**
     * A pattern matching one row tuple and capturing field $index. Strings are
     * single quoted with backslash escapes (optionally `_binary` prefixed); any
     * other value runs to the next comma or closing parenthesis. Possessive
     * quantifiers keep PCRE from backtracking through large JSON values.
     */
    protected function tuplePattern(int $index): string
    {
        $field = "(?:_binary )?'(?:[^'\\\\]++|\\\\.)*+'|[^,)']*+";
        $before = $index > 0 ? "(?:(?:{$field}),){{$index}}" : '';

        return "/\\({$before}({$field})(?:,(?:{$field}))*+\\)/s";
    }

    protected function fieldValue(string $raw): ?string
    {
        if ($raw === 'NULL') {
            return null;
        }

        if (str_starts_with($raw, '_binary ')) {
            $raw = substr($raw, 8);
        }

        if (str_starts_with($raw, "'")) {
            return stripcslashes(substr($raw, 1, -1));
        }

        return $raw;
    }

    /**
     * Quoted values are dates compared as text (MySQL writes them sortably);
     * bare numbers are Unix timestamps. Rows with no date are dropped.
     *
     * @param  array{skip: bool, column: ?string, cutoff: ?string, cutoffTimestamp: ?int}  $rule
     */
    protected function isRecent(?string $value, array $rule): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_numeric($value)) {
            return (int) $value >= $rule['cutoffTimestamp'];
        }

        return $value >= $rule['cutoff'];
    }
}
