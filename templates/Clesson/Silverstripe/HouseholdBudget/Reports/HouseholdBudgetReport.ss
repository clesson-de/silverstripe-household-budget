<style>
body { font-family: dejavusans; color: #243447; font-size: 10pt; }
h1 { color: #193b5a; font-size: 20pt; margin-bottom: 4px; }
h2 { color: #193b5a; font-size: 14pt; margin: 12px 0 6px; }
.muted { color: #5c6670; font-size: 8pt; }
.overview { border: 1px solid #cbd5df; padding: 8px; }
.metric { width: 49%; border-bottom: 1px solid #e3e8ed; padding: 6px; }
.positive { color: #18743b; font-weight: bold; }
.negative { color: #b42318; font-weight: bold; }
.transfer { color: #2093e5; }
table { border-collapse: collapse; font-size: 8pt; }
th { background-color: #eaf0f5; color: #193b5a; font-weight: bold; }
th, td { border: 1px solid #d8e0e7; padding: 4px; }
</style>
<h1>$Title.XML</h1>
<p><strong>$PeriodLabel.XML: $Period.XML</strong><br />$CreatedLabel.XML: $Created.XML<br />$AccountsLabel.XML: $Accounts.XML</p>
<p class="muted"><strong>$Disclaimer.XML</strong></p>
<table class="overview" cellpadding="4">
    <tr><td class="metric">$IncomeLabel.XML</td><td class="metric" align="right">$Income.XML</td></tr>
    <tr><td class="metric">$ExpenseLabel.XML</td><td class="metric" align="right">$Expenses.XML</td></tr>
    <tr><td class="metric">$BalanceLabel.XML</td><td class="metric $BalanceClass.XML" align="right" color="$BalanceColor.XML">$Balance.XML</td></tr>
    <tr><td class="metric">$NormalAverageLabel.XML</td><td class="metric" align="right">$NormalAverage.XML</td></tr>
    <tr><td class="metric">$TotalAverageLabel.XML</td><td class="metric" align="right">$TotalAverage.XML</td></tr>
    <tr><td class="metric">$ExceptionalLabel.XML</td><td class="metric" align="right">$Exceptional.XML</td></tr>
    <tr><td class="metric transfer" color="#2093e5">$TransferLabel.XML</td><td class="metric transfer" align="right" color="#2093e5">$Transfers.XML</td></tr>
</table>
<p class="muted">$Legend.XML<br />$TransferNote.XML</p>
<p class="muted">$AverageNote.XML</p>
