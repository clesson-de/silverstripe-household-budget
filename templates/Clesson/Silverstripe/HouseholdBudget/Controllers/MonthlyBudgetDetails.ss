<div class="household-budget-details" data-details-url="$DetailsURL.ATT" data-error-message="<%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.DETAILS_LOAD_ERROR 'Could not load the monthly details.' %>">
    <div class="household-budget-details__selectors">
        <label>
            <%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.YEAR 'Year' %>
            <select class="household-budget-details__year no-change-track form-control">
                <% loop $Years %>
                    <option value="$Value"<% if $Selected %> selected="selected"<% end_if %>>$Value</option>
                <% end_loop %>
            </select>
        </label>
        <label>
            <%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.MONTH 'Month' %>
            <select class="household-budget-details__month no-change-track form-control">
                <% loop $Months %>
                    <option value="$Value"<% if $Selected %> selected="selected"<% end_if %>>$Title</option>
                <% end_loop %>
            </select>
        </label>
    </div>
    <div class="household-budget-chart__accounts" role="group" aria-label="<%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.ACCOUNTS 'Bank accounts' %>">
        <% loop $Accounts %>
            <button type="button" class="btn <% if $Selected %>btn-primary<% else %>btn-outline-primary<% end_if %> household-budget-details__account no-change-track" aria-pressed="<% if $Selected %>true<% else %>false<% end_if %>" data-account-id="$AccountID">$Title</button>
        <% end_loop %>
    </div>
    <p class="household-budget-details__status" role="status" hidden><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.LOADING 'Loading...' %></p>
    <p class="household-budget-details__error alert alert-danger" role="alert" hidden></p>
    <div class="household-budget-details__content">
        <% with $Details %>
            <h2>$Month $Up.Year</h2>
            <h3><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.INCOME 'Income' %></h3>
            <% include Clesson\Silverstripe\HouseholdBudget\Includes\MonthlyDetailEntries Entries=$IncomeEntries, Total=$Income %>
            <h3><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.EXPENSES 'Expenses' %></h3>
            <% include Clesson\Silverstripe\HouseholdBudget\Includes\MonthlyDetailEntries Entries=$ExpenseEntries, Total=$Expenses %>
            <% if $TransferEntries %>
                <h3><%t Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryType.TRANSFER 'Transfer / reserve' %></h3>
                <% include Clesson\Silverstripe\HouseholdBudget\Includes\MonthlyDetailEntries Entries=$TransferEntries, Total=$Transfers %>
            <% end_if %>
            <div class="household-budget-details__balance <% if $NegativeBalance %>household-budget-details__balance--negative<% else %>household-budget-details__balance--positive<% end_if %>">
                <strong><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.BALANCE 'Balance' %></strong>
                <strong>$Balance</strong>
            </div>
        <% end_with %>
    </div>
</div>
