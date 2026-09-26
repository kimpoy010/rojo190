// Live "accounting format" (thousands separators, e.g. 1,234.56) for any
// money-amount <input>. Opt in by adding class="amount-input" — no other
// wiring needed, this delegates from the document so it works on fields
// swapped in later (a panel refresh, a modal) too. Add data-decimals="0"
// for a whole-number-only field (a bet/ticket amount); it defaults to 2
// (a cash amount) otherwise.
//
// The field's own .value is the FORMATTED string (with commas) while the
// user's looking at it — that's the point — so anything that reads it for
// real use (placing a bet, submitting a form) must go through
// window.parseAmount() first to get a clean, comma-free number back.
// Every plain <form> submission already gets this for free (see the
// capture-phase submit listener below); a handful of pages that read an
// amount-input's value via JS without ever firing a submit event (a button
// that builds its own FormData, a "confirm amount" prompt) call
// parseAmount() directly at that one call site instead.
(function () {
    function decimalsFor(el) {
        return el.dataset.decimals !== undefined ? parseInt(el.dataset.decimals, 10) : 2;
    }

    window.formatAccounting = function (rawValue, decimals = 2) {
        if (!rawValue) return '';

        const [wholeRaw, ...rest] = String(rawValue).replace(/[^0-9.]/g, '').split('.');
        const whole = wholeRaw.replace(/^0+(?=\d)/, '');
        const formattedWhole = whole ? Number(whole).toLocaleString('en-US') : '';

        if (decimals === 0) return formattedWhole;

        const decimal = rest.length ? rest.join('').slice(0, decimals) : null;

        return decimal !== null ? `${formattedWhole || '0'}.${decimal}` : formattedWhole;
    };

    window.parseAmount = function (value) {
        if (value === null || value === undefined) return '';

        return String(value).replace(/,/g, '').trim();
    };

    window.applyAccountingFormat = function (el) {
        el.value = window.formatAccounting(el.value, decimalsFor(el));
    };

    document.addEventListener('input', (e) => {
        const el = e.target;
        if (!el.classList || !el.classList.contains('amount-input')) return;

        // Reformatting shifts characters around the cursor (commas
        // appear/disappear), so track position by digit count rather than
        // raw index — land after the same digit the user was just after,
        // regardless of how many commas moved around it.
        const digitsBeforeCursor = el.value.slice(0, el.selectionStart).replace(/[^0-9]/g, '').length;

        window.applyAccountingFormat(el);

        let seen = 0;
        let pos = 0;
        while (pos < el.value.length && seen < digitsBeforeCursor) {
            if (/[0-9]/.test(el.value[pos])) seen++;
            pos++;
        }
        el.setSelectionRange(pos, pos);
    });

    // Covers every plain <form> submission (native POST or a data-ajax
    // handler reading FormData in its own bubble-phase listener) in one
    // place — capture phase so this always runs first, stripping commas
    // before anything else reads the form's fields.
    document.addEventListener('submit', (e) => {
        if (!(e.target instanceof HTMLFormElement)) return;

        e.target.querySelectorAll('.amount-input').forEach((el) => {
            el.value = window.parseAmount(el.value);
        });
    }, true);

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.amount-input').forEach(window.applyAccountingFormat);
    });
})();
