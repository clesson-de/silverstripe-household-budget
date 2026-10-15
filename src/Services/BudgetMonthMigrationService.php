<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Services;

use Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField;
use Clesson\Silverstripe\HouseholdBudget\Models\BudgetEntry;
use SilverStripe\ORM\DB;

/**
 * Preserves legacy date bounds when upgrading to month-only storage.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Services
 */
class BudgetMonthMigrationService
{
    /**
     * Copies legacy bounds once, retaining archived dates without restoring cleared months.
     */
    public function migrate(): void
    {
        $table = BudgetEntry::getSchema()->tableName(BudgetEntry::class);
        $fields = DB::field_list($table);
        foreach (['StartDate' => BudgetEntryField::START_MONTH, 'EndDate' => BudgetEntryField::END_MONTH] as $old => $new) {
            if (isset($fields[$old])) {
                $this->migrateColumn($table, $old, $new);
            }
        }
    }

    /**
     * Copies dates as YYYY-MM without overwriting new values, then archives the source.
     */
    private function migrateColumn(string $table, string $old, string $new): void
    {
        DB::query(
            'UPDATE "' . $table . '" SET "' . $new . '" = SUBSTRING("' . $old . '", 1, 7)'
                . ' WHERE ("' . $new . '" IS NULL OR "' . $new . '" = \'\')'
                . ' AND "' . $old . '" IS NOT NULL'
        );
        DB::get_schema()->renameField($table, $old, '_migrated_' . $old);
        DB::alteration_message('Migrated household budget ' . $old . ' to ' . $new, 'changed');
    }
}
