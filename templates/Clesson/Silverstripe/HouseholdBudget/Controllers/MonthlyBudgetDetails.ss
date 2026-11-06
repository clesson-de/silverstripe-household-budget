<% require css('clesson-de/silverstripe-gridfield-pro:client/dist/css/silverstripe-gridfield-pro.css') %>
<div class="household-budget-details" data-details-url="$DetailsURL.ATT" data-error-message="<%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.DETAILS_LOAD_ERROR 'Could not load the monthly details.' %>">
    <div class="gridfield-pro-toolbar household-budget-details__toolbar" role="group" aria-label="<%t Clesson\Silverstripe\Forms\GridField\GridFieldToolbar.LABEL 'List controls' %>">
    <div class="gridfield-pro-toolbar__row">
    <div class="gridfield-pro-toolbar__group gridfield-pro-toolbar__group--left">
    <div class="gridfield-pro-toolbar__item household-budget-details__selectors">
        <label>
            <span class="visually-hidden"><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.YEAR 'Year' %></span>
            <select class="household-budget-details__year no-change-track form-select">
                <% loop $Years %>
                    <option value="$Value"<% if $Selected %> selected="selected"<% end_if %>>$Value</option>
                <% end_loop %>
            </select>
        </label>
        <label>
            <span class="visually-hidden"><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.MONTH 'Month' %></span>
            <select class="household-budget-details__month no-change-track form-select">
                <% loop $Months %>
                    <option value="$Value"<% if $Selected %> selected="selected"<% end_if %>>$Title</option>
                <% end_loop %>
            </select>
        </label>
    </div>
    </div>
    <div class="gridfield-pro-toolbar__group gridfield-pro-toolbar__group--right household-budget-details__account-group">
        <div class="gridfield-pro-toolbar__item gridfield-pro-toolbar__item--grow">
            <% include Clesson\Silverstripe\HouseholdBudget\Includes\AccountSelect HideLabel=1 %>
        </div>
    </div>
    </div>
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
