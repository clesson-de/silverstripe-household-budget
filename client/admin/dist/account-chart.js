(function ($) {
    'use strict';

    function updatePanel(panel) {
        var buttons = panel.find('.household-budget-chart__account');
        buttons.each(function () {
            var selected = $(this).attr('aria-pressed') === 'true';
            $(this).toggleClass('btn-primary', selected).toggleClass('btn-outline-primary', !selected);
        });
        var selectedData = buttons.filter('[aria-pressed="true"]').map(function () {
            return JSON.parse(this.dataset.chartData);
        }).get();
        if (!selectedData.length) {
            return;
        }
        var totals = selectedData[0].datasets.map(function (dataset, index) {
            return dataset.data.map(function (_, month) {
                return selectedData.reduce(function (total, data) {
                    return total + Math.round(data.datasets[index].data[month] * 100);
                }, 0);
            });
        });
        var canvas = panel.find('.chart-field-canvas')[0];
        if (canvas) {
            var chart = canvas._chartInstance;
            if (!chart) {
                throw new Error('Household budget chart is not initialized.');
            }
            chart.data.datasets.forEach(function (dataset, index) {
                dataset.data = totals[index].map(function (cents) { return cents / 100; });
            });
            chart.update();
        } else {
            panel.find('tbody tr').each(function (month) {
                $(this).find('[data-budget-series]').each(function () {
                    var cents = totals[Number(this.dataset.budgetSeries)][month];
                    var absolute = Math.abs(cents);
                    var whole = Math.floor(absolute / 100).toLocaleString('de-DE');
                    var fraction = String(absolute % 100).padStart(2, '0');
                    this.textContent = (cents < 0 ? '-' : '') + whole + ',' + fraction + ' \u20ac';
                });
            });
        }
    }

    $.entwine('ss', function ($) {
        $('.household-budget-chart__account').entwine({
            onclick: function (event) {
                event.preventDefault();
                event.stopPropagation();
                var panel = this.closest('.household-budget-account-panel');
                var buttons = panel.find('.household-budget-chart__account');
                var active = this.attr('aria-pressed') !== 'true';
                this.attr('aria-pressed', String(active));
                if (!buttons.filter('[aria-pressed="true"]').length) {
                    buttons.attr('aria-pressed', 'true');
                }
                updatePanel(panel);
                return false;
            }
        });

        $('.household-budget-summary__year').entwine({
            onchange: async function () {
                var select = this[0];
                var panel = this.closest('.household-budget-summary');
                var activeIDs = panel.find('.household-budget-chart__account[aria-pressed="true"]').map(function () {
                    return this.dataset.accountId;
                }).get();
                var error = panel.find('.household-budget-summary__error')[0];
                error.hidden = true;
                select.disabled = true;
                try {
                    var url = new URL(select.dataset.summaryUrl, window.location.href);
                    url.searchParams.set('year', select.value);
                    var response = await fetch(url, { credentials: 'same-origin' });
                    if (!response.ok) {
                        throw new Error('Year summary request failed: ' + response.status);
                    }
                    var container = document.createElement('div');
                    container.innerHTML = await response.text();
                    var replacement = container.querySelector('.household-budget-summary');
                    if (!replacement) {
                        throw new Error('Year summary response has no panel.');
                    }
                    var buttons = replacement.querySelectorAll('.household-budget-chart__account');
                    var selected = Array.from(buttons).filter(function (button) {
                        return activeIDs.includes(button.dataset.accountId);
                    });
                    buttons.forEach(function (button) {
                        button.setAttribute('aria-pressed', 'false');
                    });
                    (selected.length ? selected : Array.from(buttons)).forEach(function (button) {
                        button.setAttribute('aria-pressed', 'true');
                    });
                    updatePanel($(replacement));
                    panel.replaceWith(replacement);
                } catch (failure) {
                    error.textContent = select.dataset.errorMessage;
                    error.hidden = false;
                    console.error('[HouseholdBudget]', failure);
                } finally {
                    select.disabled = false;
                }
            }
        });
    });
})(jQuery);
