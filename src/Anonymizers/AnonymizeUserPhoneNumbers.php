<?php

namespace Rouxtaccess\Sync\Anonymizers;

use Rouxtaccess\Sync\Anonymizers\Concerns\AnonymizesTable;

/**
 * Replaces phone number columns on the users table with unique, obviously fake
 * numbers. Any of the candidate columns that exist are scrubbed; the rest are
 * ignored, so it is safe whether your schema uses phone_number, msisdn, or
 * something else. Register it in config/sync.php under `anonymizers`.
 */
class AnonymizeUserPhoneNumbers
{
    use AnonymizesTable;

    /**
     * @var array<int, string>
     */
    protected array $columns = ['phone_number', 'msisdn', 'phone', 'mobile', 'mobile_number', 'cell'];

    public function __invoke(string $connection): void
    {
        $this->anonymize(
            $connection,
            'users',
            $this->columns,
            fn (string $column, object $row): string => '+1555'.str_pad((string) $row->id, 7, '0', STR_PAD_LEFT),
        );
    }
}
