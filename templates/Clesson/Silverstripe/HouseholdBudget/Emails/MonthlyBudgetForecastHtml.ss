<h1>$Subject.XML</h1>
<p>$Greeting.XML</p>
<p><strong>$Month.XML</strong> — $Scope.XML</p>
<% if $NoBookings %><p>$NoBookingsMessage.XML</p><% end_if %>

<table>
    <tbody>
        <tr><th align="left">$IncomeLabel.XML</th><td>$Income.XML</td></tr>
        <tr><th align="left">$NormalCostsLabel.XML</th><td>$NormalCosts.XML</td></tr>
        <tr><th align="left">$PeriodicCostsLabel.XML</th><td>$PeriodicCosts.XML</td></tr>
        <tr><th align="left">$ExceptionalLabel.XML</th><td>$Exceptional.XML</td></tr>
        <tr><th align="left">$TotalCostsLabel.XML</th><td>$TotalCosts.XML</td></tr>
        <tr><th align="left">$BalanceLabel.XML</th><td>$Balance.XML</td></tr>
        <tr><th align="left">$TransfersLabel.XML</th><td>$Transfers.XML</td></tr>
    </tbody>
</table>
<p>$BalanceMessage.XML</p>

<h2>$ComparisonLabel.XML</h2>
<p>$Comparison.XML</p>
<p>$AnnualAverageLabel.XML: $AnnualAverage.XML<% if $HasComparison %> ($ComparisonPercent.XML)<% end_if %></p>

<% if $VariableGuidance %><p>$VariableGuidance.XML</p><% end_if %>
<% if $HasTopExpenses %>
    <h2>$TopExpenseTitle.XML</h2>
    <ul><% loop $TopExpenses %><li>$Description.XML<% if $Counterparty %> — $Counterparty.XML<% end_if %>: $Amount.XML</li><% end_loop %></ul>
<% end_if %>
<% if $HasVariable %>
    <h2>$VariableTitle.XML</h2>
    <p>$VariableCostsLabel.XML: $VariableCosts.XML</p>
    <ul><% loop $VariablePositions %><li>$Description.XML<% if $Counterparty %> — $Counterparty.XML<% end_if %>: $Amount.XML</li><% end_loop %></ul>
<% end_if %>
<% if $HasPeriodic %><h2>$PeriodicTitle.XML</h2><ul><% loop $PeriodicPositions %><li>$Description.XML<% if $Counterparty %> — $Counterparty.XML<% end_if %>: $Amount.XML</li><% end_loop %></ul><% end_if %>
<% if $HasExceptional %><h2>$ExceptionalTitle.XML</h2><ul><% loop $ExceptionalPositions %><li>$Description.XML<% if $Counterparty %> — $Counterparty.XML<% end_if %>: $Amount.XML</li><% end_loop %></ul><% end_if %>
<% if $HasTransfers %><h2>$TransferTitle.XML</h2><ul><% loop $TransferPositions %><li>$Description.XML<% if $Counterparty %> — $Counterparty.XML<% end_if %>: $Amount.XML</li><% end_loop %></ul><% end_if %>

<p>$Disclaimer.XML</p>
