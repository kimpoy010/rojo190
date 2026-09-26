@extends('layouts.app')

@section('title', __('Teller Dashboard'))

@php
    $statusLabels = [
        'completed' => __('Approved'),
        'cancelled' => __('Rejected'),
        'expired' => __('Expired'),
    ];
    $typeLabel = fn ($type) => $type === 'deposit' ? __('Deposit') : __('Withdrawal');
@endphp

@section('content')
<div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
    <h1 class="text-2xl font-bold">{{ __('Teller Dashboard') }}</h1>
    <div class="flex items-center gap-2 text-sm">
        <a href="{{ route('teller.rfid.index') }}" class="rounded-full border border-slate-700 bg-slate-800 hover:bg-slate-700 hover:border-slate-600 hover:text-white transition text-slate-300 font-medium px-3.5 py-1.5">{{ __('RFID Cards') }}</a>
        <a href="{{ route('teller.station.index') }}" class="rounded-full border border-slate-700 bg-slate-800 hover:bg-slate-700 hover:border-slate-600 hover:text-white transition text-slate-300 font-medium px-3.5 py-1.5">{{ __('Station') }}</a>
        <a href="{{ route('teller.tickets.create') }}" class="rounded-full border border-slate-700 bg-slate-800 hover:bg-slate-700 hover:border-slate-600 hover:text-white transition text-slate-300 font-medium px-3.5 py-1.5">{{ __('Place Bet') }}</a>
        <a href="{{ route('teller.shifts.history') }}" class="rounded-full border border-slate-700 bg-slate-800 hover:bg-slate-700 hover:border-slate-600 hover:text-white transition text-slate-300 font-medium px-3.5 py-1.5">{{ __('Shift history') }}</a>
        <a href="{{ route('teller.shift.end') }}" class="rounded-full bg-amber-600 hover:bg-amber-500 transition font-semibold px-4 py-1.5 text-white">{{ __('End Shift') }}</a>
    </div>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6">
    <p class="text-xs uppercase text-slate-500 mb-3">{{ __('Current shift · started :time', ['time' => $shift->started_at->format('M j, g:i A')]) }}</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
        <div>
            <p class="text-xs text-slate-500 mb-1">{{ __('Starting cash') }}</p>
            <p class="font-bold">{{ $currencySymbol }}{{ number_format($shift->starting_cash, 2) }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-500 mb-1">{{ __('Cash in (:count)', ['count' => $totals['deposit_count'] + $totals['ticket_stake_count']]) }}</p>
            <p class="font-bold text-emerald-400">+{{ $currencySymbol }}{{ number_format($totals['deposits'] + $totals['ticket_stakes'], 2) }}</p>
            @if ($totals['ticket_stake_count'] > 0)
                <p class="text-[10px] text-slate-600">{{ trans_choice('incl. :count ticket written|incl. :count tickets written', $totals['ticket_stake_count'], ['count' => $totals['ticket_stake_count']]) }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500 mb-1">{{ __('Cash out (:count)', ['count' => $totals['withdrawal_count'] + $totals['ticket_redeemed_count']]) }}</p>
            <p class="font-bold text-red-400">-{{ $currencySymbol }}{{ number_format($totals['withdrawals'] + $totals['ticket_payouts'], 2) }}</p>
            @if ($totals['ticket_redeemed_count'] > 0)
                <p class="text-[10px] text-slate-600">{{ trans_choice('incl. :count ticket redeemed|incl. :count tickets redeemed', $totals['ticket_redeemed_count'], ['count' => $totals['ticket_redeemed_count']]) }}</p>
            @endif
        </div>
        <div>
            <p class="text-xs text-slate-500 mb-1">{{ __('Expected on hand') }}</p>
            <p class="font-bold text-amber-400">{{ $currencySymbol }}{{ number_format($totals['expected_cash'], 2) }}</p>
        </div>
    </div>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-8 max-w-md">
    <h2 class="font-semibold mb-3">{{ __('Scan or enter a code') }}</h2>
    <p class="text-sm text-slate-400 mb-3">
        {{ __("Scan the player's QR with your phone's camera to open it directly, or type the code shown under their QR here.") }}
    </p>
    <form method="POST" action="{{ route('teller.lookup') }}" class="flex gap-2">
        @csrf
        <input type="text" name="code" id="teller-lookup-code" required placeholder="{{ __('e.g. AB12CD34EF56') }}" autofocus
               class="flex-1 rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 uppercase">
        <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2">{{ __('Go') }}</button>
    </form>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-8">
    <h2 class="font-semibold mb-1 px-1">{{ __('Pending requests') }}</h2>
    <p class="text-xs text-slate-500 px-1 mb-2">{{ __("Includes RFID kiosk top-ups, which don't hand you a code to scan.") }}</p>
    <div class="divide-y divide-slate-800/60">
        @forelse ($pending as $tx)
            @php $playerName = $tx->user->username ?? $tx->user->name; @endphp
            <a href="{{ route('teller.transactions.show', $tx) }}" class="flex items-center justify-between px-1 py-3 hover:bg-slate-800/40 rounded-lg transition gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-100 truncate">
                        {{ $typeLabel($tx->type) }} &middot; {{ $playerName }}
                        @if ($tx->origin === 'rfid')
                            <span class="text-xs font-normal px-2 py-0.5 rounded-full ml-1 bg-sky-700 text-sky-100">RFID</span>
                        @endif
                    </p>
                    <p class="text-xs text-slate-500">{{ $tx->created_at->format('h:i A') }} &middot; {{ __('expires :time', ['time' => $tx->expires_at->diffForHumans()]) }}</p>
                </div>
                <span class="font-semibold whitespace-nowrap {{ $tx->type === 'deposit' ? 'text-emerald-400' : 'text-red-400' }}">
                    {{ $currencySymbol }}{{ number_format($tx->amount, 2) }}
                </span>
            </a>
        @empty
            <p class="py-4 px-1 text-slate-500 text-sm">{{ __('No pending requests.') }}</p>
        @endforelse
    </div>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
    <h2 class="font-semibold mb-1 px-1">{{ __('Processed this shift') }}</h2>
    <div class="divide-y divide-slate-800/60">
        @forelse ($recent as $tx)
            @php
                $playerName = $tx->user->username ?? $tx->user->name;
                $statusLabel = $statusLabels[$tx->status] ?? ucfirst($tx->status);
                $isDeposit = $tx->type === 'deposit';
                $isCompleted = $tx->status === 'completed';
            @endphp
            <button type="button"
                class="teller-tx-row w-full text-left px-1 py-3 hover:bg-slate-800/40 rounded-lg transition"
                data-title="{{ __(':type for :player', ['type' => $typeLabel($tx->type), 'player' => $playerName]) }}"
                data-player="{{ $playerName }}"
                data-status-label="{{ $statusLabel }}"
                data-type="{{ $tx->type }}"
                data-amount="{{ number_format($tx->amount, 2) }}"
                data-code="{{ $tx->code }}"
                data-datetime="{{ $tx->completed_at?->format('F j, Y \a\t g:i A') ?? '—' }}">
                <div class="flex items-center justify-between text-xs text-slate-500">
                    <span>{{ __(':status :type on', ['status' => $statusLabel, 'type' => $typeLabel($tx->type)]) }}</span>
                    <span>{{ $tx->completed_at?->format('h:i A') }}</span>
                </div>
                <div class="flex items-center justify-between mt-1 gap-3">
                    <span class="font-semibold text-slate-100 truncate">
                        {{ $playerName }}
                        <span class="text-xs font-normal px-2 py-0.5 rounded-full ml-1
                            {{ $isCompleted ? 'bg-emerald-700 text-emerald-100' : ($tx->status === 'cancelled' ? 'bg-slate-700 text-slate-300' : 'bg-red-800 text-red-100') }}">
                            {{ strtoupper($statusLabel) }}
                        </span>
                    </span>
                    <span class="font-semibold whitespace-nowrap {{ $isDeposit ? 'text-emerald-400' : 'text-red-400' }}">
                        {{ $isDeposit ? '+' : '-' }}{{ $currencySymbol }}{{ number_format($tx->amount, 2) }}
                    </span>
                </div>
            </button>
        @empty
            <p class="py-4 px-1 text-slate-500 text-sm">{{ __('No transactions processed yet.') }}</p>
        @endforelse
    </div>
</div>

<!-- Transaction detail modal -->
<div id="teller-tx-modal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/60 backdrop-blur-sm px-4">
    <div class="w-full sm:max-w-sm bg-slate-900 border border-slate-800 rounded-t-2xl sm:rounded-2xl p-5 pb-6">
        <div class="flex items-center justify-between mb-4">
            <p class="font-semibold text-slate-200">{{ __('Transaction details') }}</p>
            <button type="button" id="teller-tx-modal-close" class="text-slate-500 hover:text-white transition text-xl leading-none">&times;</button>
        </div>

        <p class="text-center text-2xl font-extrabold mb-1" id="teller-tx-modal-amount"></p>
        <p class="text-center text-sm text-slate-400 mb-5" id="teller-tx-modal-title"></p>

        <dl class="space-y-3 text-sm">
            <div class="flex justify-between">
                <dt class="text-slate-500">{{ __('Player') }}</dt>
                <dd class="text-slate-200 text-right" id="teller-tx-modal-player"></dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">{{ __('Date & time') }}</dt>
                <dd class="text-slate-200 text-right" id="teller-tx-modal-datetime"></dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">{{ __('Status') }}</dt>
                <dd class="text-slate-200 text-right" id="teller-tx-modal-status"></dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">{{ __('Reference code') }}</dt>
                <dd class="text-slate-200 text-right font-mono" id="teller-tx-modal-code"></dd>
            </div>
        </dl>
    </div>
</div>

@push('scripts')
<script>
    // The `autofocus` attribute on the code field isn't reliable everywhere
    // (Firefox's session restore, some redirect paths) — this is the
    // teller's most-used field on this page (every scan or manual lookup
    // starts here), so reinforce it with an explicit focus on load.
    document.getElementById('teller-lookup-code')?.focus();
</script>
<script>
    (function () {
        const modal = document.getElementById('teller-tx-modal');
        if (!modal) return;

        const amountEl = document.getElementById('teller-tx-modal-amount');
        const titleEl = document.getElementById('teller-tx-modal-title');
        const playerEl = document.getElementById('teller-tx-modal-player');
        const datetimeEl = document.getElementById('teller-tx-modal-datetime');
        const statusEl = document.getElementById('teller-tx-modal-status');
        const codeEl = document.getElementById('teller-tx-modal-code');
        const closeBtn = document.getElementById('teller-tx-modal-close');

        const open = (row) => {
            const isDeposit = row.dataset.type === 'deposit';
            amountEl.textContent = (isDeposit ? '+' : '-') + @json($currencySymbol) + row.dataset.amount;
            amountEl.className = 'text-center text-2xl font-extrabold mb-1 ' + (isDeposit ? 'text-emerald-400' : 'text-red-400');
            titleEl.textContent = row.dataset.title;
            playerEl.textContent = row.dataset.player;
            datetimeEl.textContent = row.dataset.datetime;
            statusEl.textContent = row.dataset.statusLabel;
            codeEl.textContent = row.dataset.code;
            modal.classList.remove('hidden');
        };

        const close = () => modal.classList.add('hidden');

        document.querySelectorAll('.teller-tx-row').forEach((row) => {
            row.addEventListener('click', () => open(row));
        });

        closeBtn.addEventListener('click', close);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) close();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') close();
        });
    })();
</script>
@endpush
@endsection
