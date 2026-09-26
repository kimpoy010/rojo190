@extends('layouts.app')

@section('title', __('End Shift'))

@section('content')
<div class="max-w-sm mx-auto">
    <h1 class="text-2xl font-bold mb-2">{{ __('End Your Shift') }}</h1>
    <p class="text-sm text-slate-400 mb-6">{{ __('Count the cash in your drawer now and enter it below to close out.') }}</p>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6">
        <p class="text-xs uppercase text-slate-500 mb-3">{{ __('System totals for this shift') }}</p>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between">
                <dt class="text-slate-400">{{ __('Starting cash') }}</dt>
                <dd class="font-semibold">{{ $currencySymbol }}{{ number_format($shift->starting_cash, 2) }}</dd>
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
        </dl>
    </div>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <form method="POST" action="{{ route('teller.shift.close') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-sm text-slate-400 mb-1">{{ __('Actual cash counted') }}</label>
                <input type="text" inputmode="decimal" name="actual_cash" required autofocus placeholder="{{ __('e.g. :amount', ['amount' => '8500']) }}"
                       class="amount-input w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-lg">
            </div>
            <button class="w-full rounded-lg bg-amber-600 hover:bg-amber-500 transition font-semibold py-2">{{ __('Close Shift') }}</button>
            <a href="{{ route('teller.dashboard') }}" class="block text-center text-sm text-slate-400 hover:text-white transition">{{ __('Cancel') }}</a>
        </form>
    </div>
</div>
@endsection
