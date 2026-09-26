@extends('layouts.app')

@section('title', __('Cash Request'))

@php
    $typeLabel = $cashTransaction->type === 'deposit' ? __('Deposit') : __('Withdrawal');
    $statusWord = __($cashTransaction->status);
@endphp

@section('content')
<div class="max-w-md mx-auto">
    <a href="{{ route('teller.dashboard') }}" class="text-sm text-slate-400 hover:text-red-400">&larr; {{ __('Dashboard') }}</a>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-6 mt-4">
        <p class="text-xs uppercase text-slate-500 mb-1">{{ __(':type request', ['type' => $typeLabel]) }}</p>
        <p class="text-3xl font-bold mb-4">{{ $currencySymbol }}{{ number_format($cashTransaction->amount, 2) }}</p>

        <dl class="space-y-2 text-sm mb-6">
            <div class="flex justify-between">
                <dt class="text-slate-500">{{ __('Player') }}</dt>
                <dd class="font-semibold">{{ $cashTransaction->user->username ?? $cashTransaction->user->name }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">{{ __('Wallet balance') }}</dt>
                <dd>{{ $currencySymbol }}{{ number_format($cashTransaction->user->wallet->main_balance ?? 0, 2) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">{{ __('Status') }}</dt>
                <dd class="font-semibold">{{ $statusWord }}</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-slate-500">{{ __('Code') }}</dt>
                <dd class="font-mono text-xs">{{ $cashTransaction->code }}</dd>
            </div>
        </dl>

        @if ($cashTransaction->status === 'pending')
            @if ($cashTransaction->type === 'deposit')
                <div class="rounded-lg border border-sky-700 bg-sky-900/30 px-3 py-2 text-sm text-sky-200 mb-4">
                    {{ __('Collect :amount from the player (cash or e-wallet) before approving.', ['amount' => $currencySymbol.number_format($cashTransaction->amount, 2)]) }}
                </div>
            @else
                <div class="rounded-lg border border-sky-700 bg-sky-900/30 px-3 py-2 text-sm text-sky-200 mb-4">
                    {{ __("Approving deducts :amount from the player's wallet — hand them the payment after.", ['amount' => $currencySymbol.number_format($cashTransaction->amount, 2)]) }}
                </div>
            @endif

            <div class="flex gap-2">
                <form method="POST" action="{{ route('teller.transactions.approve', $cashTransaction) }}" class="flex-1">
                    @csrf
                    <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold py-2">{{ __('Approve') }}</button>
                </form>
                <form method="POST" action="{{ route('teller.transactions.reject', $cashTransaction) }}" class="flex-1" onsubmit="return confirm('{{ __('Reject this request?') }}')">
                    @csrf
                    <button class="w-full rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold py-2">{{ __('Reject') }}</button>
                </form>
            </div>
        @else
            <p class="text-slate-400 text-sm">{{ __('This request is :status — nothing more to do here.', ['status' => $statusWord]) }}</p>
        @endif
    </div>
</div>
@endsection
