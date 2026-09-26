@extends('layouts.app')

@section('title', __('Player Transactions'))

@section('content')
<a href="{{ route('superadmin.wallets.index') }}" class="text-sm text-slate-400 hover:text-white transition">&larr; {{ __('Back to Player Wallets') }}</a>

<div class="flex items-center justify-between mb-6 mt-2 gap-3 flex-wrap">
    <div>
        <h1 class="text-2xl font-bold">{{ $targetUser->displayName() }}</h1>
        <p class="text-sm text-slate-500">{{ $targetUser->email }}</p>
    </div>
    <div class="text-right">
        <p class="text-xs text-slate-500 uppercase tracking-wide">{{ __('Balance') }}</p>
        <p class="text-xl font-extrabold text-emerald-400">{{ $currencySymbol }}{{ number_format($wallet->main_balance ?? 0, 2) }}</p>
    </div>
</div>

@php
    $tabLabels = [
        'all' => __('All Transactions'),
        'bets' => __('Bets'),
        'deposits' => __('Deposits'),
        'withdrawals' => __('Withdrawals'),
    ];
@endphp
<div class="flex gap-1.5 bg-slate-900 border border-slate-800 rounded-xl p-1 mb-4 max-w-xl" id="wallet-tx-tabs">
    @foreach ($tabLabels as $tabKey => $tabLabel)
        <button type="button" data-tab="{{ $tabKey }}"
           class="wallet-tx-tab flex-1 text-center rounded-lg py-2 text-xs font-bold transition {{ $tab === $tabKey ? 'bg-red-600 text-white' : 'text-slate-400 hover:text-white' }}">
            {{ $tabLabel }}
        </button>
    @endforeach
</div>

<form id="wallet-tx-filter-form" method="GET" action="{{ route('superadmin.wallets.transactions', $targetUser) }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6 flex flex-wrap items-end gap-3">
    <input type="hidden" name="tab" id="wallet-tx-tab-field" value="{{ $tab }}">
    @include('partials.date-range-field', ['id' => 'wallet-tx-date', 'fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])
    <div id="wallet-tx-event-group" @if ($tab !== 'bets') hidden @endif>
        <label class="block text-xs text-slate-500 mb-1">{{ __('Event') }}</label>
        <select name="event_id" id="wallet-tx-event-select" class="rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-sm">
            <option value="">{{ __('All events') }}</option>
            @foreach ($playerEvents as $playerEvent)
                <option value="{{ $playerEvent->id }}" @selected($eventId === $playerEvent->id)>{{ $playerEvent->name }}</option>
            @endforeach
        </select>
    </div>
    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2 text-sm">{{ __('Filter') }}</button>
    <a id="wallet-tx-clear" href="{{ route('superadmin.wallets.transactions', [$targetUser, 'tab' => $tab]) }}" @if (! ($dateFrom || $dateTo || $eventId)) hidden @endif class="text-sm text-slate-400 hover:text-white transition px-2 py-2">{{ __('Clear') }}</a>
</form>

<div id="wallet-tx-results">
    @include('superadmin.wallets._transactions-rows')
</div>

@push('scripts')
<script>
    (function () {
        const form = document.getElementById('wallet-tx-filter-form');
        const results = document.getElementById('wallet-tx-results');
        const tabField = document.getElementById('wallet-tx-tab-field');
        const eventGroup = document.getElementById('wallet-tx-event-group');
        const clearLink = document.getElementById('wallet-tx-clear');
        const tabs = document.querySelectorAll('.wallet-tx-tab');
        const baseUrl = @json(route('superadmin.wallets.transactions', $targetUser));

        function updateEventVisibility() {
            eventGroup.hidden = tabField.value !== 'bets';
        }

        function setActiveTabStyle() {
            tabs.forEach((btn) => {
                const active = btn.dataset.tab === tabField.value;
                btn.classList.toggle('bg-red-600', active);
                btn.classList.toggle('text-white', active);
                btn.classList.toggle('text-slate-400', !active);
            });
        }

        function hasActiveFilters() {
            return Array.from(form.elements).some((el) => {
                if (el === tabField || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return false;
                return el.value !== '';
            });
        }

        function buildUrl() {
            const params = new URLSearchParams(new FormData(form));
            return `${baseUrl}?${params.toString()}`;
        }

        function go(url) {
            window.AjaxList.load(url, results);
            clearLink.hidden = !hasActiveFilters();
        }

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            go(buildUrl());
        });

        // Switching tabs resets every filter — a date range or event picked
        // on one tab isn't guaranteed to mean anything on another (the Bets
        // tab's Event dropdown, for one, has no equivalent elsewhere) —
        // same convention as the player's own wallet page.
        tabs.forEach((btn) => {
            btn.addEventListener('click', () => {
                if (btn.dataset.tab === tabField.value) return;
                tabField.value = btn.dataset.tab;
                window.AjaxList.clearFormFields(form);
                updateEventVisibility();
                setActiveTabStyle();
                go(buildUrl());
            });
        });

        clearLink.addEventListener('click', (e) => {
            e.preventDefault();
            window.AjaxList.clearFormFields(form);
            go(buildUrl());
        });

        window.AjaxList.bindPagination(results, go);

        updateEventVisibility();
    })();
</script>
@endpush
@endsection
