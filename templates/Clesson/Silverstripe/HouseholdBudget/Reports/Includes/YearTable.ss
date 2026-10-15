<table cellpadding="4">
    <thead><tr>
        <th width="8%">$YearLabel.XML</th><th width="15%">$IncomeLabel.XML</th>
        <th width="16%">$NormalCostsLabel.XML</th><th width="14%">$ExceptionalLabel.XML</th>
        <th width="16%">$ExpensesLabel.XML</th><th width="14%">$TransfersLabel.XML</th>
        <th width="17%">$BalanceLabel.XML</th>
    </tr></thead>
    <tbody><% loop $Rows %>
        <tr>
            <td>$Year.XML $Partial.XML</td><td align="right">$Income.XML</td>
            <td align="right">$NormalCosts.XML</td><td align="right">$Exceptional.XML</td>
            <td align="right">$Expenses.XML</td><td align="right" color="#2093e5">$Transfers.XML</td>
            <td align="right">$Balance.XML</td>
        </tr>
    <% end_loop %></tbody>
</table>
<p>$PartialNote.XML</p>
