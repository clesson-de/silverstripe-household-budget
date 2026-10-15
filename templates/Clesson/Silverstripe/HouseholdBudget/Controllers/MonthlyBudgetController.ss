<div class="household-budget-summary household-budget-account-panel">
    <h2><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.YEAR_SUMMARY 'Year summary' %> $Year</h2>
    <label>
        <%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.YEAR 'Year' %>
        <select class="household-budget-summary__year no-change-track" data-summary-url="$SummaryURL.ATT" data-error-message="<%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.YEAR_LOAD_ERROR 'Could not load the selected year.' %>">
            <% loop $Years %>
                <option value="$Value"<% if $Selected %> selected="selected"<% end_if %>>$Value</option>
            <% end_loop %>
        </select>
    </label>
    <p class="household-budget-summary__error alert alert-danger" role="alert" hidden></p>
    <% include Clesson\Silverstripe\HouseholdBudget\Includes\AccountButtons %>
    <table class="table">
        <thead>
            <tr>
                <th><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.MONTH 'Month' %></th>
                <th>$IncomeLabel</th>
                <th>$ExpensesLabel</th>
                <th>$BalanceLabel</th>
            </tr>
        </thead>
        <tbody>
            <% loop $Months %>
                <tr>
                    <th scope="row">$Month</th>
                    <td data-budget-series="0">$Income</td>
                    <td data-budget-series="1">$Expenses</td>
                    <td data-budget-series="2">$Balance</td>
                </tr>
            <% end_loop %>
        </tbody>
    </table>
</div>
