<section class="household-budget-report"
    data-download-url="$DownloadURL.XML"
    data-security-token="$SecurityToken.XML"
    data-select-account-error="$SelectAccountError.XML"
    data-invalid-range-error="$InvalidRangeError.XML"
    data-download-error="$DownloadError.XML"
    data-processing-label="$Processing.XML"
    aria-labelledby="household-budget-report-title">
    <h2 id="household-budget-report-title">$Title.XML</h2>
    <p>$Description.XML</p>
    <% if $HasAccounts %>
        <div class="household-budget-report__dates">
            <label>
                <span>$StartMonthLabel.XML</span>
                <input class="form-control no-change-track" type="month" name="startMonth" value="$CurrentYear.XML-01" required />
            </label>
            <label>
                <span>$EndMonthLabel.XML</span>
                <input class="form-control no-change-track" type="month" name="endMonth" value="$CurrentYear.XML-12" required />
            </label>
        </div>
        <div class="household-budget-report__accounts household-budget-chart__accounts field">
            <label>
                $AccountsLabel.XML
                <select multiple class="household-budget-report__account-select no-change-track" name="accountIDs[]" aria-label="$AccountsLabel.XML">
                    <% loop $Accounts %>
                        <option value="$ID.XML" selected="selected">$Title.XML</option>
                    <% end_loop %>
                </select>
            </label>
        </div>
        <button class="btn btn-primary household-budget-report__download" type="button">$SubmitLabel.XML</button>
        <p class="household-budget-report__status" role="status" aria-live="polite" hidden></p>
        <p class="household-budget-report__error" role="alert" hidden></p>
    <% else %>
        <p class="household-budget-report__empty">$NoAccounts.XML</p>
        <button class="btn btn-primary" type="button" disabled>$SubmitLabel.XML</button>
    <% end_if %>
</section>
