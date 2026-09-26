import flatpickr from 'flatpickr';
import { Spanish } from 'flatpickr/dist/l10n/es.js';

// Every date-range filter across the app (audit trail, wallet reports,
// income/cash-flow/accounting reports, the player's own wallet tabs) shares
// this one widget instead of a pair of native <input type="date"> fields.
// Opt in with data-date-range plus data-range-from/data-range-to selectors
// pointing at the two hidden inputs that actually carry the from/to values
// (form field names, or plain ids read by a page's own filter JS) — this
// only ever touches those two elements' .value, so nothing downstream (form
// submission, a page's existing state object) needs to change.
(function () {
    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function toYmd(date) {
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    }

    function parseYmd(value) {
        return value ? new Date(`${value}T00:00:00`) : null;
    }

    window.initDateRangePickers = function (root = document) {
        root.querySelectorAll('[data-date-range]').forEach((input) => {
            if (input._flatpickr) return;

            const fromEl = document.querySelector(input.dataset.rangeFrom);
            const toEl = document.querySelector(input.dataset.rangeTo);
            if (!fromEl || !toEl) return;

            const clearBtn = input.parentElement.querySelector('[data-date-range-clear]');

            const picker = flatpickr(input, {
                mode: 'range',
                dateFormat: 'M j, Y',
                locale: document.documentElement.lang === 'es' ? Spanish : undefined,
                defaultDate: [fromEl.value, toEl.value].filter(Boolean).map(parseYmd),
                onChange(selectedDates) {
                    fromEl.value = selectedDates[0] ? toYmd(selectedDates[0]) : '';
                    toEl.value = selectedDates[1] ? toYmd(selectedDates[1]) : '';
                    if (clearBtn) clearBtn.hidden = selectedDates.length === 0;
                },
            });

            if (clearBtn) {
                clearBtn.hidden = !fromEl.value && !toEl.value;
                clearBtn.addEventListener('click', () => picker.clear());
            }
        });
    };

    document.addEventListener('DOMContentLoaded', () => window.initDateRangePickers());
})();
