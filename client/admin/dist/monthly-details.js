(function ($) {
    'use strict';

    var requests = new WeakMap();

    async function reload(panel) {
        var previous = requests.get(panel);
        if (previous) {
            previous.abort();
        }
        var request = new AbortController();
        requests.set(panel, request);
        var status = panel.querySelector('.household-budget-details__status');
        var error = panel.querySelector('.household-budget-details__error');
        panel.setAttribute('aria-busy', 'true');
        status.hidden = false;
        error.hidden = true;
        try {
            var url = new URL(panel.dataset.detailsUrl, window.location.href);
            url.searchParams.set('year', panel.querySelector('.household-budget-details__year').value);
            var month = panel.querySelector('.household-budget-details__month');
            if (month) {
                url.searchParams.set('month', month.value);
            }
            var type = panel.querySelector('.household-budget-ranking__type[aria-pressed="true"]');
            if (type) {
                url.searchParams.set('type', type.dataset.type);
            }
            var accounts = Array.from(panel.querySelector('.household-budget-account-select').selectedOptions);
            if (accounts.length) {
                url.searchParams.set('accounts', accounts.map(function (button) {
                    return button.value;
                }).join(','));
            }
            var response = await fetch(url, { credentials: 'same-origin', signal: request.signal });
            if (!response.ok) {
                throw new Error('Monthly details request failed: ' + response.status);
            }
            var container = document.createElement('div');
            container.innerHTML = await response.text();
            var replacement = container.querySelector('.household-budget-details');
            if (!replacement) {
                throw new Error('Monthly details response has no view.');
            }
            if (requests.get(panel) === request && !request.signal.aborted && panel.isConnected) {
                $(panel).replaceWith(replacement);
            }
        } catch (failure) {
            if (!request.signal.aborted && requests.get(panel) === request) {
                error.textContent = panel.dataset.errorMessage;
                error.hidden = false;
                console.error('[HouseholdBudget]', failure);
            }
        } finally {
            if (requests.get(panel) === request) {
                requests.delete(panel);
                panel.setAttribute('aria-busy', 'false');
                status.hidden = true;
            }
        }
    }

    $.entwine('ss', function ($) {
        $('.household-budget-ranking__type').entwine({
            onclick: function () {
                var panel = this.closest('.household-budget-details');
                panel.find('.household-budget-ranking__type')
                    .attr('aria-pressed', 'false').removeClass('btn-primary').addClass('btn-outline-primary');
                this.attr('aria-pressed', 'true').removeClass('btn-outline-primary').addClass('btn-primary');
                reload(panel[0]);
                return false;
            }
        });

        $('.household-budget-details__year, .household-budget-details__month').entwine({
            onchange: function () {
                reload(this.closest('.household-budget-details')[0]);
            }
        });

        $('.household-budget-details .household-budget-account-select').entwine({
            onchange: function () {
                var panel = this.closest('.household-budget-details');
                if (!this.val() || !this.val().length) {
                    this.find('option').prop('selected', true);
                    this.trigger('chosen:updated');
                }
                reload(panel[0]);
            }
        });
    });
})(jQuery);
