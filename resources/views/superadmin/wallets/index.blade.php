@extends('layouts.app')

@section('title', __('Player wallets'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('Player Wallets') }}</h1>

<div class="relative mb-4 max-w-sm ml-auto">
    <input type="text" id="wallet-search" value="{{ $q }}" placeholder="{{ __('Search by name, username or email…') }}"
           autocomplete="off"
           class="w-full rounded-lg bg-slate-800 border border-slate-700 pl-3 pr-9 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
    <span id="wallet-search-spinner" class="hidden absolute inset-y-0 right-3 flex items-center text-slate-500 text-xs">…</span>
</div>

{{-- Repopulated in place as the player types (see the script below) and
     when a pagination link is clicked, instead of a full page reload —
     the search box's own value survives either way since it never gets
     wiped out along with this container. --}}
<div id="wallet-results">
    @include('superadmin.wallets._rows')
</div>

{{-- One shared modal each for credit/debit, populated from whichever row's
     button was clicked (see the script below) — not one pair per row,
     since the row list itself gets replaced wholesale by the search/
     pagination fetch. Submits as a normal form POST, same as before this
     was a modal; only the inline-input-in-every-row UI changed. --}}
<div id="wallet-credit-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4">
    <div class="w-full max-w-sm bg-slate-900 border border-slate-800 rounded-xl p-5">
        <div class="flex items-center justify-between mb-4">
            <p class="font-semibold">{{ __('Credit') }} <span id="wallet-credit-modal-name" class="text-emerald-400"></span></p>
            <button type="button" class="wallet-modal-cancel text-slate-500 hover:text-white transition text-xl leading-none" data-modal="wallet-credit-modal">&times;</button>
        </div>
        <form method="POST" id="wallet-credit-modal-form">
            @csrf
            <label class="block text-xs text-slate-500 mb-1">{{ __('Amount') }}</label>
            <input type="text" inputmode="decimal" name="amount" required autofocus
                   class="amount-input w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 mb-4">
            <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold px-4 py-2">{{ __('Credit') }}</button>
        </form>
    </div>
</div>

<div id="wallet-debit-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4">
    <div class="w-full max-w-sm bg-slate-900 border border-slate-800 rounded-xl p-5">
        <div class="flex items-center justify-between mb-4">
            <p class="font-semibold">{{ __('Debit') }} <span id="wallet-debit-modal-name" class="text-red-400"></span></p>
            <button type="button" class="wallet-modal-cancel text-slate-500 hover:text-white transition text-xl leading-none" data-modal="wallet-debit-modal">&times;</button>
        </div>
        <form method="POST" id="wallet-debit-modal-form">
            @csrf
            <label class="block text-xs text-slate-500 mb-1">{{ __('Amount') }}</label>
            <input type="text" inputmode="decimal" name="amount" required autofocus
                   class="amount-input w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 mb-4">
            <button class="w-full rounded-lg bg-red-700 hover:bg-red-600 transition font-semibold px-4 py-2">{{ __('Debit') }}</button>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const input = document.getElementById('wallet-search');
    const spinner = document.getElementById('wallet-search-spinner');
    const results = document.getElementById('wallet-results');
    const baseUrl = @json(route('superadmin.wallets.index'));
    let debounceTimer = null;
    let requestSeq = 0;

    function load(q, page) {
        const url = new URL(baseUrl, window.location.origin);
        if (q) url.searchParams.set('q', q);
        if (page && page > 1) url.searchParams.set('page', page);

        const seq = ++requestSeq;
        spinner.classList.remove('hidden');

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
            .then((r) => r.text())
            .then((html) => {
                // Ignore a stale response that lost the race to a newer
                // keystroke's request.
                if (seq !== requestSeq) return;
                results.innerHTML = html;
                history.replaceState(null, '', url);
            })
            .catch(() => {})
            .finally(() => {
                if (seq === requestSeq) spinner.classList.add('hidden');
            });
    }

    input.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        const q = input.value.trim();
        debounceTimer = setTimeout(() => load(q, 1), 300);
    });

    // Pagination links render as plain <a href> inside a <nav> (Laravel's
    // default pagination view) — intercept clicks on those specifically so
    // paging stays in place too, rather than only the search. Scoped to
    // that <nav> (not every <a> in the results) so it doesn't also swallow
    // an unrelated link like a row's "View transactions".
    results.addEventListener('click', (e) => {
        const link = e.target.closest('nav[role="navigation"] a[href]');
        if (!link) return;

        e.preventDefault();
        const page = new URL(link.href, window.location.origin).searchParams.get('page') || 1;
        load(input.value.trim(), page);
    });

    // Credit/debit modals — delegated on the results container since its
    // rows (and their buttons) get replaced wholesale by the fetch above.
    function wireModal(kind) {
        const modal = document.getElementById('wallet-' + kind + '-modal');
        const nameEl = document.getElementById('wallet-' + kind + '-modal-name');
        const form = document.getElementById('wallet-' + kind + '-modal-form');

        return (btn) => {
            nameEl.textContent = btn.dataset.name;
            form.action = btn.dataset.url;
            modal.classList.remove('hidden');
            form.querySelector('input[name=amount]').focus();
        };
    }

    const openCredit = wireModal('credit');
    const openDebit = wireModal('debit');

    results.addEventListener('click', (e) => {
        const creditBtn = e.target.closest('.wallet-credit-btn');
        if (creditBtn) return openCredit(creditBtn);

        const debitBtn = e.target.closest('.wallet-debit-btn');
        if (debitBtn) return openDebit(debitBtn);
    });

    document.querySelectorAll('.wallet-modal-cancel').forEach((btn) => {
        btn.addEventListener('click', () => document.getElementById(btn.dataset.modal).classList.add('hidden'));
    });
    document.querySelectorAll('#wallet-credit-modal, #wallet-debit-modal').forEach((modal) => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.add('hidden');
        });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.getElementById('wallet-credit-modal').classList.add('hidden');
        document.getElementById('wallet-debit-modal').classList.add('hidden');
    });
})();
</script>
@endpush
@endsection
