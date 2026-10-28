<table class="table household-budget-details__table">
    <thead>
        <tr>
            <th scope="col"><%t Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField.DESCRIPTION 'Description' %></th>
            <th scope="col"><%t Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField.ACCOUNT 'Bank account' %></th>
            <th scope="col"></th>
            <th scope="col" class="household-budget-details__amount"><%t Clesson\Silverstripe\HouseholdBudget\Constants\BudgetEntryField.AMOUNT 'Amount' %></th>
        </tr>
    </thead>
    <tbody>
        <% loop $Entries %>
            <tr>
                <td>$Description<% if $Counterparty %><br><small>$Counterparty</small><% end_if %></td>
                <td>$Account</td>
                <td>
                    <div class="household-budget-details__months">
                        <% loop $Months %>
                            <span class="household-budget-details__month<% if $IsCurrent %> household-budget-details__month--current<% end_if %>">$Label</span>
                        <% end_loop %>
                    </div>
                </td>
                <td class="household-budget-details__amount">$Amount</td>
            </tr>
        <% end_loop %>
        <% if not $Entries %>
            <tr><td colspan="4"><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.NO_ENTRIES 'No bookings for this selection.' %></td></tr>
        <% end_if %>
    </tbody>
    <tfoot>
        <tr>
            <th scope="row" colspan="3"><%t Clesson\Silverstripe\HouseholdBudget\Controllers\MonthlyBudgetController.TOTAL 'Total' %></th>
            <td class="household-budget-details__amount"><strong>$Total</strong></td>
        </tr>
    </tfoot>
</table>
