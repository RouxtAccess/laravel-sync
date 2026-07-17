<?php

namespace Rouxtaccess\Sync\Anonymizers;

use Rouxtaccess\Sync\Anonymizers\Concerns\AnonymizesTable;

/**
 * Replaces every users.email with a unique, obviously fake address so nobody is
 * emailed from a local copy. Register it in config/sync.php under `anonymizers`.
 * Copy this class to target other tables or columns.
 */
class AnonymizeUserEmails
{
    use AnonymizesTable;

    public function __invoke(string $connection): void
    {
        $this->anonymize($connection, 'users', ['email'], fn (string $column, object $row): string => "user{$row->id}@example.test");
    }
}
