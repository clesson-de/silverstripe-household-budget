<div class="household-budget-chart__accounts field">
    <label>
        <%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.ACCOUNTS 'Bank accounts' %>
        <select multiple class="household-budget-account-select no-change-track" aria-label="<%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.ACCOUNTS 'Bank accounts' %>">
            <% loop $Accounts %>
                <option value="$AccountID.ATT"<% if $Selected || $Up.ChartMode %> selected="selected"<% end_if %> data-account-id="$AccountID.ATT"<% if $Up.ChartMode %> data-chart-data="$ChartData.ATT"<% end_if %>>$Title.XML</option>
            <% end_loop %>
        </select>
    </label>
</div>
