<div class="household-budget-chart__accounts" role="group" aria-label="<%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.ACCOUNTS 'Bank accounts' %>">
    <% loop $Accounts %>
        <button type="button" class="btn btn-primary household-budget-chart__account no-change-track" aria-pressed="true" data-account-id="$AccountID" data-chart-data="$ChartData.ATT">$Title</button>
    <% end_loop %>
</div>
