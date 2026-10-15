<div class="household-budget-details household-budget-annual-ranking" data-details-url="$DetailsURL.ATT" data-error-message="<%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.RANKING_LOAD_ERROR 'Could not load the annual ranking. Previous values remain visible.' %>">
    <div class="household-budget-details__selectors">
        <label>
            <%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.YEAR 'Year' %>
            <select class="household-budget-details__year no-change-track form-control">
                <% loop $Years %>
                    <option value="$Value"<% if $Selected %> selected="selected"<% end_if %>>$Value</option>
                <% end_loop %>
            </select>
        </label>
    </div>
    <div class="btn-group household-budget-entry-type-filter" role="group" aria-label="<%t Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField.TYPE 'Transaction type' %>">
        <% loop $Types %>
            <button type="button" class="btn <% if $Selected %>btn-primary<% else %>btn-outline-primary<% end_if %> household-budget-ranking__type no-change-track" aria-pressed="<% if $Selected %>true<% else %>false<% end_if %>" data-type="$Value.ATT">$Title</button>
        <% end_loop %>
    </div>
    <div class="household-budget-chart__accounts" role="group" aria-label="<%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.ACCOUNTS 'Bank accounts' %>">
        <% loop $Accounts %>
            <button type="button" class="btn <% if $Selected %>btn-primary<% else %>btn-outline-primary<% end_if %> household-budget-details__account no-change-track" aria-pressed="<% if $Selected %>true<% else %>false<% end_if %>" data-account-id="$AccountID">$Title</button>
        <% end_loop %>
    </div>
    <p class="household-budget-details__status" role="status" hidden><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.LOADING 'Loading...' %></p>
    <p class="household-budget-details__error alert alert-danger" role="alert" hidden></p>
    <% if $HasPositions %>
        $RankingChart
    <% else %>
        <p><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.NO_ENTRIES 'No bookings for this selection.' %></p>
    <% end_if %>
</div>
