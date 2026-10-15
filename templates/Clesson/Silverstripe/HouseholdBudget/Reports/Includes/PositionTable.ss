<table cellpadding="3">
    <thead><tr>
        <th width="20%">$DescriptionLabel.XML</th><th width="14%">$CounterpartyLabel.XML</th>
        <th width="15%">$AccountLabel.XML</th><th width="12%">$AmountLabel.XML</th>
        <th width="9%">$OccurrencesLabel.XML</th><th width="16%">$MonthsLabel.XML</th>
        <th width="14%">$TotalLabel.XML</th>
    </tr></thead>
    <tbody><% loop $Rows %>
        <tr>
            <td>$Description.XML</td><td>$Counterparty.XML</td><td>$Account.XML</td>
            <td align="right">$Amount.XML</td><td align="right">$Occurrences.XML</td>
            <td>$Months.XML</td><td align="right">$Total.XML</td>
        </tr>
    <% end_loop %></tbody>
</table>
