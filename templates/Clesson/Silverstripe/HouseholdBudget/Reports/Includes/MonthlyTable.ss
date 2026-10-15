<table cellpadding="3">
    <thead><tr>
        <th width="19%">$MonthLabel.XML</th>
        <th width="13%">$IncomeLabel.XML</th>
        <th width="15%">$NormalCostsLabel.XML</th>
        <th width="13%">$ExceptionalLabel.XML</th>
        <th width="15%">$ExpensesLabel.XML</th>
        <th width="12%">$TransfersLabel.XML</th>
        <th width="13%">$BalanceLabel.XML</th>
    </tr></thead>
    <tbody>
    <% loop $Rows %>
        <tr>
            <td>$Month.XML</td><td align="right">$Income.XML</td><td align="right">$NormalCosts.XML</td>
            <td align="right">$Exceptional.XML</td><td align="right">$Expenses.XML</td>
            <td align="right" color="#2093e5">$Transfers.XML</td>
            <td align="right" color="$BalanceColor.XML">$Balance.XML</td>
        </tr>
    <% end_loop %>
    </tbody>
</table>
