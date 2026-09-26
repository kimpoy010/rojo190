// Shared primitives + a generic wiring helper for AJAX-driven admin list
// pages (filter form + paginated results, no full page reload) — the same
// pattern the player's own wallet page pioneered, factored out so every
// other admin list (audit trail, wallet transactions, the accounting
// reports) can opt in without re-implementing fetch/swap/pagination
// handling from scratch. Every page that opts in must already return the
// same results partial from its controller when the request is ajax().
(function () {
    function load(url, resultsEl) {
        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
            .then((r) => r.text())
            .then((html) => { resultsEl.innerHTML = html; })
            .catch(() => {});
    }

    // Scoped to Laravel's own pagination markup (<nav role="navigation">)
    // rather than every <a> in the results container — a results table can
    // easily have its own real navigation links (a "View bets" drill-down,
    // for instance) that must keep behaving like normal links, not get
    // hijacked into an AJAX fetch.
    function bindPagination(resultsEl, onPage) {
        resultsEl.addEventListener('click', (e) => {
            const link = e.target.closest('nav[role="navigation"] a[href]');
            if (!link) return;
            e.preventDefault();
            onPage(link.href);
        });
    }

    // Blanks every real filter control in a form without touching hidden
    // fields directly — a hidden field either carries state that must
    // survive a "Clear" (e.g. a wallet-transactions page's current tab) or
    // is one half of a date-range picker, cleared through its own
    // flatpickr instance instead so the visible widget updates too.
    function clearFormFields(formEl) {
        Array.from(formEl.elements).forEach((el) => {
            if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return;
            if (el.tagName === 'SELECT') {
                el.selectedIndex = 0;
                return;
            }
            el.value = '';
        });
        formEl.querySelectorAll('[data-date-range]').forEach((input) => {
            if (input._flatpickr) input._flatpickr.clear();
        });
    }

    window.AjaxList = { load, bindPagination, clearFormFields };

    // Generic wiring for the common case: one filter <form>, one results
    // container, an optional "Clear" link, and optionally other links (e.g.
    // an "Export CSV" button) whose query string should keep tracking
    // whatever's currently filtered — otherwise, once a filter changes via
    // AJAX without the address bar itself changing, a stale export link
    // would silently export the wrong rows.
    window.initAjaxFilterList = function ({ form, results, clear, syncLinks }) {
        const formEl = document.querySelector(form);
        const resultsEl = document.querySelector(results);
        if (!formEl || !resultsEl) return;

        function buildUrl() {
            const params = new URLSearchParams(new FormData(formEl));
            const base = formEl.getAttribute('action') || window.location.pathname;
            return `${base}?${params.toString()}`;
        }

        function syncExtraLinks(url) {
            if (!syncLinks) return;
            const query = new URL(url, window.location.origin).search;
            document.querySelectorAll(syncLinks).forEach((link) => {
                const linkUrl = new URL(link.href, window.location.origin);
                linkUrl.search = query;
                link.href = linkUrl.toString();
            });
        }

        // The "Clear" link's visibility is normally decided server-side
        // (only shown once a filter is actually active) — once filtering
        // happens over AJAX instead of a full reload, that server-rendered
        // state goes stale the first time a filter changes without it, so
        // this re-derives it from the form's own current values instead.
        function updateClearVisibility() {
            if (!clear) return;
            const active = Array.from(formEl.elements).some((el) => {
                if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return false;
                return el.value !== '';
            });
            document.querySelectorAll(clear).forEach((link) => { link.hidden = !active; });
        }

        function go(url) {
            syncExtraLinks(url);
            load(url, resultsEl);
            updateClearVisibility();
        }

        formEl.addEventListener('submit', (e) => {
            e.preventDefault();
            go(buildUrl());
        });

        if (clear) {
            document.querySelectorAll(clear).forEach((link) => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    clearFormFields(formEl);
                    go(link.href);
                });
            });
        }

        bindPagination(resultsEl, go);
    };
})();
