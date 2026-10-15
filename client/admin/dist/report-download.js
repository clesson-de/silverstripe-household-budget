(function () {
    'use strict';

    function setError(panel, message) {
        var error = panel.querySelector('.household-budget-report__error');
        error.textContent = message;
        error.hidden = false;
    }

    function safeFilename(startMonth, endMonth) {
        return ('haushaltsbericht-' + startMonth + '-bis-' + endMonth + '.pdf')
            .replace(/[^a-z0-9.-]/gi, '-')
            .slice(0, 80);
    }

    async function download(panel, button) {
        var startMonth = panel.querySelector('[name="startMonth"]').value;
        var endMonth = panel.querySelector('[name="endMonth"]').value;
        var accounts = Array.from(panel.querySelectorAll('[name="accountID"]:checked'));
        var error = panel.querySelector('.household-budget-report__error');
        var status = panel.querySelector('.household-budget-report__status');
        if (accounts.length === 0) {
            setError(panel, panel.dataset.selectAccountError);
            return;
        }
        if (!/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/.test(startMonth)
            || !/^(?!0000)\d{4}-(0[1-9]|1[0-2])$/.test(endMonth)
            || startMonth > endMonth) {
            setError(panel, panel.dataset.invalidRangeError);
            return;
        }
        button.disabled = true;
        error.hidden = true;
        status.hidden = false;
        status.textContent = panel.dataset.processingLabel;
        var failureMessage = panel.dataset.downloadError;
        try {
            var body = new FormData();
            body.append('SecurityID', panel.dataset.securityToken);
            body.append('startMonth', startMonth);
            body.append('endMonth', endMonth);
            accounts.forEach(function (account) {
                body.append('accountIDs[]', account.value);
            });
            var response = await fetch(panel.dataset.downloadUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            });
            if (!response.ok) {
                var errorData = await response.json();
                if (errorData && typeof errorData.error === 'string') {
                    failureMessage = errorData.error;
                }
                throw new Error(failureMessage);
            }
            var contentType = response.headers.get('Content-Type') || '';
            if (!contentType.includes('application/pdf')) {
                throw new Error(panel.dataset.downloadError);
            }
            var blob = await response.blob();
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url;
            link.download = safeFilename(startMonth, endMonth);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        } catch {
            setError(panel, failureMessage);
        } finally {
            status.hidden = true;
            button.disabled = false;
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.household-budget-report__download');
        if (!button || button.disabled) {
            return;
        }
        var panel = button.closest('.household-budget-report');
        if (panel) {
            download(panel, button);
        }
    });
})();
