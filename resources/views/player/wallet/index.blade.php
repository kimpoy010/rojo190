@extends('layouts.app')

@section('title', __('My Wallet'))

@section('content')
<p class="text-[10.5px] font-extrabold tracking-[0.16em] text-[#e0793a] mb-1">{{ __('POOL SABONG') }}</p>
<h1 class="text-2xl font-extrabold tracking-tight mb-6">{{ __('My Wallet') }}</h1>

<div class="relative rounded-2xl overflow-hidden p-6 mb-4 border border-red-900/25" style="background: radial-gradient(circle at 15% -10%, #7a1f14, transparent 55%), linear-gradient(160deg, #1c110c, #0e0805);">
    <p class="text-[10.5px] font-extrabold tracking-[0.1em] text-[#c99a7a]">{{ __('WALLET BALANCE') }}</p>
    <p class="text-4xl font-extrabold text-amber-400 mt-1.5" style="text-shadow: 0 0 24px rgba(251,191,36,0.25);">{{ $currencySymbol }}<span data-wallet-balance="main">{{ number_format($wallet->main_balance ?? 0, 2) }}</span></p>
    @if (($wallet->pending_withdrawal ?? 0) >= 0.01)
        <p class="text-xs text-amber-400 mt-1">{{ __(':amount held for a pending withdrawal', ['amount' => $currencySymbol.number_format($wallet->pending_withdrawal, 2)]) }}</p>
    @endif

    <div class="grid grid-cols-2 gap-2.5 mt-5">
        <a href="{{ route('play.cash.index') }}#cash-in" class="text-center rounded-lg bg-red-600 hover:bg-red-500 transition font-extrabold text-sm py-2.5 shadow-[0_4px_14px_-4px_rgba(220,38,38,0.6)]">{{ __('Deposit') }}</a>
        <a href="{{ route('play.cash.index') }}#cash-out" class="text-center rounded-lg bg-white/[0.06] border border-white/[0.14] hover:bg-white/[0.1] transition font-extrabold text-sm py-2.5">{{ __('Withdraw') }}</a>
    </div>
</div>

<div class="rounded-xl border border-[#2a1a14] bg-[#160e0a] p-4">
    <h2 class="font-semibold mb-3 px-1">{{ __('Transaction history') }}</h2>

    <div class="flex gap-1.5 bg-[#0b0705] border border-[#2a1a14] rounded-[11px] p-1 mb-3" id="wallet-tabs">
        <button type="button" class="wallet-tab flex-1 rounded-lg py-2 text-[11px] font-bold text-center transition" data-tab="all">{{ __('All Transactions') }}</button>
        <button type="button" class="wallet-tab flex-1 rounded-lg py-2 text-[11px] font-bold text-center transition" data-tab="bets">{{ __('Bets') }}</button>
        <button type="button" class="wallet-tab flex-1 rounded-lg py-2 text-[11px] font-bold text-center transition" data-tab="deposits">{{ __('Deposits') }}</button>
        <button type="button" class="wallet-tab flex-1 rounded-lg py-2 text-[11px] font-bold text-center transition" data-tab="withdrawals">{{ __('Withdrawals') }}</button>
    </div>

    <div class="flex flex-wrap items-end gap-2 mb-3">
        <div id="wallet-filter-type" hidden>
            <label class="block text-[10px] text-[#8a7a70] mb-1">{{ __('Type') }}</label>
            <select id="wallet-tx-filter-type" class="rounded-lg bg-[#0b0705] border border-[#2a1a14] px-2 py-1.5 text-xs">
                <option value="">{{ __('All types') }}</option>
                <option value="bet">{{ __('Bet placed') }}</option>
                <option value="payout">{{ __('Bet payout') }}</option>
                <option value="refund">{{ __('Bet refund') }}</option>
                <option value="deposit">{{ __('Cash deposit') }}</option>
                <option value="withdrawal">{{ __('Cash withdrawal') }}</option>
                <option value="reversal">{{ __('Payout reversal') }}</option>
            </select>
        </div>
        <div id="wallet-filter-event" hidden>
            <label class="block text-[10px] text-[#8a7a70] mb-1">{{ __('Event') }}</label>
            <select id="wallet-tx-filter-event" class="rounded-lg bg-[#0b0705] border border-[#2a1a14] px-2 py-1.5 text-xs">
                <option value="">{{ __('All Bets') }}</option>
                @foreach ($playerEvents as $playerEvent)
                    <option value="{{ $playerEvent->id }}">{{ $playerEvent->name }}</option>
                @endforeach
            </select>
        </div>
        <div id="wallet-filter-date-range" hidden>
            <label class="block text-[10px] text-[#8a7a70] mb-1">{{ __('Date range') }}</label>
            <div class="relative">
                <input type="text" id="wallet-tx-filter-date-range" data-date-range
                       data-range-from="#wallet-tx-filter-date-from" data-range-to="#wallet-tx-filter-date-to"
                       placeholder="{{ __('Select dates') }}" autocomplete="off" readonly
                       class="rounded-lg bg-[#0b0705] border border-[#2a1a14] pl-2 pr-6 py-1.5 text-xs cursor-pointer w-52">
                <button type="button" data-date-range-clear hidden
                        class="absolute right-1 top-1/2 -translate-y-1/2 text-[#8a7a70] hover:text-white text-sm leading-none">&times;</button>
            </div>
            <input type="hidden" id="wallet-tx-filter-date-from">
            <input type="hidden" id="wallet-tx-filter-date-to">
        </div>
        <div id="wallet-filter-amount" hidden>
            <label class="block text-[10px] text-[#8a7a70] mb-1">{{ __('Amount') }}</label>
            <input type="text" inputmode="decimal" id="wallet-tx-filter-amount" placeholder="{{ __('Amount') }}"
                   class="amount-input w-24 rounded-lg bg-[#0b0705] border border-[#2a1a14] px-2 py-1.5 text-xs">
        </div>
        <button type="button" id="wallet-tx-filter-apply" class="rounded-lg bg-red-600 hover:bg-red-500 transition text-xs font-bold px-3 py-1.5">{{ __('Filter') }}</button>
        <button type="button" id="wallet-tx-filter-clear" class="text-xs text-[#8a7a70] hover:text-white transition px-2 py-1.5" hidden>{{ __('Clear') }}</button>
    </div>

    <div id="wallet-tx-results">
        @include('player.partials.wallet-transaction-rows')
    </div>
</div>

<!-- Transaction detail modal -->
<div id="wallet-tx-modal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/60 backdrop-blur-sm px-4">
    <div class="w-full sm:max-w-sm bg-[#160e0a] border border-[#2a1a14] rounded-t-2xl sm:rounded-2xl p-5 pb-6">
        <div class="flex items-center justify-between mb-4">
            <p class="font-semibold text-[#f5efe9]">{{ __('Transaction details') }}</p>
            <button type="button" id="wallet-tx-modal-close" class="text-[#8a7a70] hover:text-white transition text-xl leading-none">&times;</button>
        </div>

        <p class="text-center text-2xl font-extrabold mb-1" id="wallet-tx-modal-amount"></p>
        <p class="text-center text-sm text-[#8a7a70] mb-5" id="wallet-tx-modal-title"></p>

        <dl class="space-y-3 text-sm">
            <div class="flex justify-between">
                <dt class="text-[#8a7a70]">{{ __('Date & time') }}</dt>
                <dd class="text-[#f5efe9] text-right" id="wallet-tx-modal-datetime"></dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-[#8a7a70]">{{ __('Type') }}</dt>
                <dd class="text-[#f5efe9] text-right capitalize" id="wallet-tx-modal-type"></dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-[#8a7a70]">{{ __('Category') }}</dt>
                <dd class="text-[#f5efe9] text-right capitalize" id="wallet-tx-modal-reference"></dd>
            </div>
            <div class="flex justify-between" id="wallet-tx-modal-event-row" hidden>
                <dt class="text-[#8a7a70]">{{ __('Event') }}</dt>
                <dd class="text-[#f5efe9] text-right" id="wallet-tx-modal-event"></dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-[#8a7a70]">{{ __('Balance after') }}</dt>
                <dd class="text-[#f5efe9] text-right" id="wallet-tx-modal-balance"></dd>
            </div>
        </dl>
    </div>
</div>

@push('scripts')
<script>
    (function () {
        const modal = document.getElementById('wallet-tx-modal');
        const results = document.getElementById('wallet-tx-results');
        if (!modal || !results) return;

        const amountEl = document.getElementById('wallet-tx-modal-amount');
        const titleEl = document.getElementById('wallet-tx-modal-title');
        const datetimeEl = document.getElementById('wallet-tx-modal-datetime');
        const typeEl = document.getElementById('wallet-tx-modal-type');
        const referenceEl = document.getElementById('wallet-tx-modal-reference');
        const eventRow = document.getElementById('wallet-tx-modal-event-row');
        const eventEl = document.getElementById('wallet-tx-modal-event');
        const balanceEl = document.getElementById('wallet-tx-modal-balance');
        const closeBtn = document.getElementById('wallet-tx-modal-close');

        const openModal = (row) => {
            const isCredit = row.dataset.type === 'credit';
            amountEl.textContent = (isCredit ? '+' : '-') + @json($currencySymbol) + row.dataset.amount;
            amountEl.className = 'text-center text-2xl font-extrabold mb-1 ' + (isCredit ? 'text-emerald-400' : 'text-red-400');
            titleEl.textContent = row.dataset.title;
            datetimeEl.textContent = row.dataset.datetime;
            typeEl.textContent = row.dataset.type;
            referenceEl.textContent = row.dataset.referenceType.replace(/_/g, ' ');
            eventRow.hidden = !row.dataset.eventName;
            eventEl.textContent = row.dataset.eventName;
            balanceEl.textContent = @json($currencySymbol) + row.dataset.balance;
            modal.classList.remove('hidden');
        };

        const closeModal = () => modal.classList.add('hidden');

        closeBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });

        // Delegated on the results container (not bound per-row) so rows
        // swapped in by the tab/filter/pagination fetch below — which
        // replace #wallet-tx-results' innerHTML wholesale — open the modal
        // too, without needing to re-bind listeners after every fetch.
        results.addEventListener('click', (e) => {
            const row = e.target.closest('.wallet-tx-row');
            if (row) openModal(row);
        });

        // --- Tabs + per-tab filters ---------------------------------
        const baseUrl = @json(route('play.wallet.index'));
        const tabs = document.querySelectorAll('.wallet-tab');
        const typeGroup = document.getElementById('wallet-filter-type');
        const eventGroup = document.getElementById('wallet-filter-event');
        const dateRangeGroup = document.getElementById('wallet-filter-date-range');
        const amountGroup = document.getElementById('wallet-filter-amount');
        const typeSelect = document.getElementById('wallet-tx-filter-type');
        const eventSelect = document.getElementById('wallet-tx-filter-event');
        const dateRangeInput = document.getElementById('wallet-tx-filter-date-range');
        const dateFromInput = document.getElementById('wallet-tx-filter-date-from');
        const dateToInput = document.getElementById('wallet-tx-filter-date-to');
        const amountInput = document.getElementById('wallet-tx-filter-amount');
        const applyBtn = document.getElementById('wallet-tx-filter-apply');
        const clearBtn = document.getElementById('wallet-tx-filter-clear');

        // Which filter groups apply to each tab — drives both which
        // controls show and which query params load() sends.
        const TAB_FILTERS = {
            all: ['type', 'date_from', 'date_to'],
            bets: ['event'],
            deposits: ['date_from', 'date_to', 'amount'],
            withdrawals: ['date_from', 'date_to', 'amount'],
        };

        // Hydrated from what the server actually rendered this page with —
        // otherwise a bookmarked/shared filtered URL, or the back button
        // landing here, would show tab/filter controls that don't match
        // the rows already on screen.
        const state = {
            tab: @json($tab),
            type: @json($type ?? ''),
            event_id: @json($eventId ? (string) $eventId : ''),
            amount: @json($amount !== null ? (string) $amount : ''),
            date_from: @json($dateFrom ?? ''),
            date_to: @json($dateTo ?? ''),
        };
        typeSelect.value = state.type;
        eventSelect.value = state.event_id;
        amountInput.value = state.amount;
        dateFromInput.value = state.date_from;
        dateToInput.value = state.date_to;

        function resetFilterInputs() {
            typeSelect.value = '';
            eventSelect.value = '';
            if (dateRangeInput._flatpickr) {
                dateRangeInput._flatpickr.clear();
            } else {
                dateFromInput.value = '';
                dateToInput.value = '';
            }
            amountInput.value = '';
            state.type = state.event_id = state.amount = state.date_from = state.date_to = '';
        }

        function updateFilterVisibility() {
            const active = TAB_FILTERS[state.tab];
            typeGroup.hidden = !active.includes('type');
            eventGroup.hidden = !active.includes('event');
            dateRangeGroup.hidden = !active.includes('date_from');
            amountGroup.hidden = !active.includes('amount');
        }

        function setActiveTabStyle() {
            tabs.forEach((tab) => {
                const active = tab.dataset.tab === state.tab;
                tab.classList.toggle('bg-red-600', active);
                tab.classList.toggle('text-white', active);
                tab.classList.toggle('shadow-[0_4px_14px_-4px_rgba(220,38,38,0.6)]', active);
                tab.classList.toggle('text-[#8a7a70]', !active);
            });
        }

        function hasActiveFilters() {
            return Object.keys(state).some((key) => key !== 'tab' && state[key]);
        }

        function load(page) {
            const url = new URL(baseUrl, window.location.origin);
            url.searchParams.set('tab', state.tab);
            if (state.type) url.searchParams.set('type', state.type);
            if (state.event_id) url.searchParams.set('event_id', state.event_id);
            if (state.amount) url.searchParams.set('amount', state.amount);
            if (state.date_from) url.searchParams.set('date_from', state.date_from);
            if (state.date_to) url.searchParams.set('date_to', state.date_to);
            if (page && page > 1) url.searchParams.set('page', page);

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
                .then((r) => r.text())
                .then((html) => { results.innerHTML = html; })
                .catch(() => {});

            clearBtn.hidden = !hasActiveFilters();
        }

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                if (tab.dataset.tab === state.tab) return;
                state.tab = tab.dataset.tab;
                resetFilterInputs();
                updateFilterVisibility();
                setActiveTabStyle();
                load(1);
            });
        });

        applyBtn.addEventListener('click', () => {
            state.type = typeSelect.value;
            state.event_id = eventSelect.value;
            // amount-input fields display comma-formatted text while
            // focused/filled (see amount-format.js) — this reads outside
            // any form submit event, so it must go through parseAmount()
            // itself instead of relying on that script's submit listener.
            state.amount = window.parseAmount ? window.parseAmount(amountInput.value) : amountInput.value;
            state.date_from = dateFromInput.value;
            state.date_to = dateToInput.value;
            load(1);
        });

        clearBtn.addEventListener('click', () => {
            resetFilterInputs();
            load(1);
        });

        // Pagination links render as plain <a href> inside the results
        // container (Laravel's default pagination view) — intercept clicks
        // on them so paging stays in place too, rather than only the tabs/
        // filters above.
        results.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link) return;

            e.preventDefault();
            const page = new URL(link.href, window.location.origin).searchParams.get('page') || 1;
            load(page);
        });

        updateFilterVisibility();
        setActiveTabStyle();
        clearBtn.hidden = !hasActiveFilters();
    })();
</script>
@endpush
@endsection
