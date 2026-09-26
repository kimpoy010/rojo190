@extends('layouts.app')

@section('title', __('Shift Report'))

@php
    $statusLabels = [
        'completed' => __('Approved'),
        'cancelled' => __('Rejected'),
        'expired' => __('Expired'),
    ];
    $typeLabel = fn ($type) => $type === 'deposit' ? __('Deposit') : __('Withdrawal');

    $variance = (float) ($tellerShift->variance ?? 0);
    $isClosed = ! $tellerShift->isOpen();
    $varianceLabel = abs($variance) < 0.01 ? __('Balanced') : ($variance > 0 ? __('Over') : __('Short'));
    $varianceColor = abs($variance) < 0.01 ? 'text-emerald-400' : 'text-red-400';
@endphp

@section('content')
<div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
    <h1 class="text-2xl font-bold">{{ __('Shift Report') }}</h1>
    <div class="flex items-center gap-3 text-sm">
        <a href="{{ route('teller.shift.report.export', $tellerShift) }}" class="text-red-400 hover:underline">{{ __('Export CSV') }}</a>
        <a href="{{ route('teller.shifts.history') }}" class="text-slate-400 hover:text-white transition">{{ __('All shifts') }}</a>
        @if (! $isClosed)
            <a href="{{ route('teller.dashboard') }}" class="rounded-full bg-emerald-600 hover:bg-emerald-500 transition font-semibold px-4 py-1.5 text-white">{{ __('Back to dashboard') }}</a>
        @endif
    </div>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6">
    <p class="text-xs uppercase text-slate-500 mb-1">
        {{ $tellerShift->started_at->format('M j, Y g:i A') }}
        &rarr;
        {{ $tellerShift->ended_at?->format('M j, Y g:i A') ?? __('still open') }}
    </p>

    <dl class="space-y-2 text-sm mt-4">
        <div class="flex justify-between">
            <dt class="text-slate-400">{{ __('Starting cash') }}</dt>
            <dd class="font-semibold">{{ $currencySymbol }}{{ number_format($tellerShift->starting_cash, 2) }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-slate-400">{{ trans_choice('Cash in (:count deposit)|Cash in (:count deposits)', $totals['deposit_count'], ['count' => $totals['deposit_count']]) }}</dt>
            <dd class="font-semibold text-emerald-400">+{{ $currencySymbol }}{{ number_format($totals['deposits'], 2) }}</dd>
        </div>
        @if ($totals['ticket_stake_count'] > 0)
            <div class="flex justify-between">
                <dt class="text-slate-400">{{ __('Tickets written (:count)', ['count' => $totals['ticket_stake_count']]) }}</dt>
                <dd class="font-semibold text-emerald-400">+{{ $currencySymbol }}{{ number_format($totals['ticket_stakes'], 2) }}</dd>
            </div>
        @endif
        <div class="flex justify-between">
            <dt class="text-slate-400">{{ trans_choice('Cash out (:count withdrawal)|Cash out (:count withdrawals)', $totals['withdrawal_count'], ['count' => $totals['withdrawal_count']]) }}</dt>
            <dd class="font-semibold text-red-400">-{{ $currencySymbol }}{{ number_format($totals['withdrawals'], 2) }}</dd>
        </div>
        @if ($totals['ticket_redeemed_count'] > 0)
            <div class="flex justify-between">
                <dt class="text-slate-400">{{ __('Tickets redeemed (:count)', ['count' => $totals['ticket_redeemed_count']]) }}</dt>
                <dd class="font-semibold text-red-400">-{{ $currencySymbol }}{{ number_format($totals['ticket_payouts'], 2) }}</dd>
            </div>
        @endif
        <div class="flex justify-between pt-2 border-t border-slate-800">
            <dt class="text-slate-300 font-semibold">{{ __('Expected cash on hand') }}</dt>
            <dd class="font-bold text-amber-400">{{ $currencySymbol }}{{ number_format($totals['expected_cash'], 2) }}</dd>
        </div>

        @if ($isClosed)
            <div class="flex justify-between">
                <dt class="text-slate-300 font-semibold">{{ __('Actual cash counted') }}</dt>
                <dd class="font-bold">{{ $currencySymbol }}{{ number_format($tellerShift->ending_cash, 2) }}</dd>
            </div>
            <div class="flex justify-between pt-2 border-t border-slate-800">
                <dt class="text-slate-300 font-semibold">{{ __('Variance') }}</dt>
                <dd class="font-extrabold {{ $varianceColor }}">
                    {{ $varianceLabel }}{{ abs($variance) >= 0.01 ? ' — '.$currencySymbol.number_format(abs($variance), 2) : '' }}
                </dd>
            </div>
        @endif
    </dl>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
    <h2 class="font-semibold mb-1 px-1">{{ __('Transactions this shift') }}</h2>
    <div class="divide-y divide-slate-800/60">
        @forelse ($tellerShift->cashTransactions()->where('status', 'completed')->with('user:id,name,username')->orderByDesc('completed_at')->get() as $tx)
            @php
                $playerName = $tx->user->username ?? $tx->user->name;
                $statusLabel = $statusLabels[$tx->status] ?? ucfirst($tx->status);
                $isDeposit = $tx->type === 'deposit';
            @endphp
            <div class="px-1 py-3">
                <div class="flex items-center justify-between text-xs text-slate-500">
                    <span>{{ __(':status :type on', ['status' => $statusLabel, 'type' => $typeLabel($tx->type)]) }}</span>
                    <span>{{ $tx->completed_at?->format('h:i A') }}</span>
                </div>
                <div class="flex items-center justify-between mt-1 gap-3">
                    <span class="font-semibold text-slate-100 truncate">{{ $playerName }}</span>
                    <span class="font-semibold whitespace-nowrap {{ $isDeposit ? 'text-emerald-400' : 'text-red-400' }}">
                        {{ $isDeposit ? '+' : '-' }}{{ $currencySymbol }}{{ number_format($tx->amount, 2) }}
                    </span>
                </div>
            </div>
        @empty
            <p class="py-4 px-1 text-slate-500 text-sm">{{ __('No transactions processed this shift.') }}</p>
        @endforelse
    </div>
</div>
@endsection
