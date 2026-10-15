<?php

declare(strict_types=1);

namespace Clesson\Silverstripe\HouseholdBudget\Admins;

use Clesson\Silverstripe\HouseholdBudget\Models\HouseholdBudget;
use Clesson\Silverstripe\HouseholdBudget\Constants\HouseholdBudgetPermission;
use SilverStripe\Admin\SingleRecordAdmin;
use SilverStripe\ORM\DataObject;

/**
 * Dedicated CMS dashboard section for household budget summaries.
 *
 * @package Clesson\Silverstripe\HouseholdBudget
 * @subpackage Admins
 */
class HouseholdBudgetDashboardAdmin extends SingleRecordAdmin
{
    private static string $url_segment = 'household-budget-dashboard';
    private static string $menu_title = 'Household budget';
    private static float $menu_priority = -0.6;
    private static string $menu_icon_class = 'font-icon-menu-reports';
    private static string $model_class = HouseholdBudget::class;
    private static bool $restrict_to_single_record = false;
    private static bool $allow_new_record = false;
    private static string $required_permission_codes = HouseholdBudgetPermission::ACCESS;

    /**
     * Returns the signed-in member's personal budget instead of a global singleton.
     */
    protected function getSingleRecord(): ?DataObject
    {
        return HouseholdBudget::currentRecord();
    }

    /**
     * Rejects explicit record IDs belonging to another member.
     */
    public function getRecord($id): ?DataObject
    {
        $record = parent::getRecord($id);
        return $record && $record->canView() ? $record : null;
    }

    /**
     * Registers access to the combined budget section in the CMS permission list.
     */
    public function providePermissions(): array
    {
        return [
            HouseholdBudgetPermission::ACCESS => [
                'name' => _t(__CLASS__ . '.ACCESS', 'Access household budget'),
                'category' => _t(__CLASS__ . '.PERMISSION_CATEGORY', 'CMS Access'),
            ],
        ];
    }
}
