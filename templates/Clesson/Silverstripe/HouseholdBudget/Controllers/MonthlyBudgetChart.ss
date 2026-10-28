<div class="household-budget-chart household-budget-details" data-details-url="$DetailsURL.ATT" data-error-message="<%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.YEAR_LOAD_ERROR 'Could not load the selected year.' %>">
    <div class="household-budget-details__selectors">
        <label>
            <%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.YEAR 'Year' %>
            <select class="household-budget-details__year no-change-track form-control">
                <% loop $Years %>
                    <option value="$Value"<% if $Selected %> selected="selected"<% end_if %>>$Value</option>
                <% end_loop %>
            </select>
        </label>
        <% if $Months %>
            <label>
                <%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.MONTH 'Month' %>
                <select class="household-budget-details__month no-change-track form-control">
                    <% loop $Months %>
                        <option value="$Value"<% if $Selected %> selected="selected"<% end_if %>>$Title</option>
                    <% end_loop %>
                </select>
            </label>
        <% end_if %>
    </div>
    <% include Clesson\Silverstripe\HouseholdBudget\Includes\AccountSelect %>
    <p class="household-budget-details__status" role="status" hidden><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.LOADING 'Loading...' %></p>
    <p class="household-budget-details__error alert alert-danger" role="alert" hidden></p>
    $Chart
</div>
