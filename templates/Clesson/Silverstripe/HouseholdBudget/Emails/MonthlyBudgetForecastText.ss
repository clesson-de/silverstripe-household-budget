$Subject

$Greeting
$Month — $Scope
<% if $NoBookings %>$NoBookingsMessage<% end_if %>

$IncomeLabel: $Income
$NormalCostsLabel: $NormalCosts
$PeriodicCostsLabel: $PeriodicCosts
$ExceptionalLabel: $Exceptional
$TotalCostsLabel: $TotalCosts
$BalanceLabel: $Balance
$TransfersLabel: $Transfers
$BalanceMessage

$ComparisonLabel: $Comparison
$AnnualAverageLabel: $AnnualAverage<% if $HasComparison %> ($ComparisonPercent)<% end_if %>

<% if $VariableGuidance %>$VariableGuidance<% end_if %>
<% if $HasTopExpenses %>$TopExpenseTitle
<% loop $TopExpenses %>- $Description<% if $Counterparty %> — $Counterparty<% end_if %>: $Amount
<% end_loop %><% end_if %>
<% if $HasVariable %>$VariableTitle ($VariableCostsLabel: $VariableCosts)
<% loop $VariablePositions %>- $Description<% if $Counterparty %> — $Counterparty<% end_if %>: $Amount
<% end_loop %><% end_if %>
<% if $HasPeriodic %>$PeriodicTitle
<% loop $PeriodicPositions %>- $Description<% if $Counterparty %> — $Counterparty<% end_if %>: $Amount
<% end_loop %><% end_if %>
<% if $HasExceptional %>$ExceptionalTitle
<% loop $ExceptionalPositions %>- $Description<% if $Counterparty %> — $Counterparty<% end_if %>: $Amount
<% end_loop %><% end_if %>
<% if $HasTransfers %>$TransferTitle
<% loop $TransferPositions %>- $Description<% if $Counterparty %> — $Counterparty<% end_if %>: $Amount
<% end_loop %><% end_if %>

$Disclaimer
