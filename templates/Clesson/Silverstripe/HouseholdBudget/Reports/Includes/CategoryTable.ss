<table cellpadding="4">
    <thead><tr>
        <th width="50%">$CategoryLabel.XML</th><th width="30%">$AmountLabel.XML</th><th width="20%">$ShareLabel.XML</th>
    </tr></thead>
    <tbody><% loop $Rows %>
        <tr><td>$Category.XML</td><td align="right">$Amount.XML</td><td align="right">$Share.XML</td></tr>
    <% end_loop %></tbody>
</table>
