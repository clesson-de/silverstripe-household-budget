<section class="household-budget-forecast-settings">
    <p>$Description.XML</p>
    <p><strong>$RecipientLabel.XML:</strong> $RecipientEmail.XML</p>
    <p>$ScopeLabel.XML</p>
    <% if $HasAccounts %>
        <fieldset>
            <legend>$Title.XML</legend>
            <% loop $Accounts %>
                <input type="hidden" name="ForecastSettings[AccountIDs][]" value="$ID.XML" />
                <label>
                    <input type="checkbox" name="ForecastSettings[EnabledIDs][]" value="$ID.XML"<% if $Enabled %> checked<% end_if %> />
                    $Title.XML
                </label>
            <% end_loop %>
        </fieldset>
    <% else %>
        <p>$NoAccounts.XML</p>
    <% end_if %>

    <h3>$HistoryLabel.XML</h3>
    <% if $HasDeliveries %>
        <table>
            <thead><tr><th>$MonthLabel.XML</th><th>$ScopeColumnLabel.XML</th><th>$StatusLabel.XML</th><th>$ErrorLabel.XML</th></tr></thead>
            <tbody>
                <% loop $Deliveries %>
                    <tr><td>$Month.XML</td><td>$Scope.XML</td><td>$Status.XML</td><td>$Result.XML</td></tr>
                <% end_loop %>
            </tbody>
        </table>
    <% else %>
        <p>$NoHistory.XML</p>
    <% end_if %>
</section>
