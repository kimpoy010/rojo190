@extends('layouts.app')

@php
    $typeLabel = $cashTransaction->type === 'deposit' ? __('Deposit') : __('Withdrawal');
@endphp

@section('title', __(':type QR', ['type' => $typeLabel]))

@section('content')
<div class="max-w-md mx-auto text-center">
    <p class="text-[10.5px] font-extrabold tracking-[0.16em] text-[#e0793a] mb-1">{{ __('POOL SABONG') }}</p>
    <h1 class="text-xl font-extrabold mb-2">{{ __(':type request', ['type' => $typeLabel]) }}</h1>
    <p class="text-3xl font-extrabold text-amber-400 mb-6" style="text-shadow: 0 0 24px rgba(251,191,36,0.25);">{{ $currencySymbol }}{{ number_format($cashTransaction->amount, 2) }}</p>

    <div id="pending-block" class="{{ $cashTransaction->status === 'pending' ? '' : 'hidden' }}">
        <div class="bg-white rounded-xl p-4 inline-block mb-4">
            {!! \App\Support\QrCodeGenerator::svg(route('teller.transactions.show', $cashTransaction)) !!}
        </div>
        <p class="text-sm text-[#c9baaf] mb-1">{{ __('Show this to the cashier to scan.') }}</p>
        <p class="text-xs text-[#8a7a70] mb-6">{{ __('Code:') }} <span class="font-mono">{{ $cashTransaction->code }}</span> — {{ __('expires :time', ['time' => $cashTransaction->expires_at->diffForHumans()]) }}</p>

        <form method="POST" action="{{ route('play.cash.cancel', $cashTransaction) }}">
            @csrf
            <button class="text-sm text-red-400 hover:underline">{{ __('Cancel this request') }}</button>
        </form>
    </div>

    <div id="completed-block" class="{{ $cashTransaction->status === 'completed' ? '' : 'hidden' }}">
        <div class="rounded-xl px-4 py-6 mb-4" style="background:rgba(6,78,59,0.35);border:1px solid rgba(16,185,129,0.4);">
            <p class="text-emerald-300 font-semibold text-lg">✅ {{ __(':type completed', ['type' => $typeLabel]) }}</p>
        </div>
        <a href="{{ route('play.cash.index') }}" class="inline-block rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-6 py-2 shadow-[0_4px_14px_-4px_rgba(220,38,38,0.6)]">{{ __('Back to Cash In / Out') }}</a>
    </div>

    <div id="cancelled-block" class="{{ in_array($cashTransaction->status, ['cancelled', 'expired']) ? '' : 'hidden' }}">
        <div class="rounded-xl px-4 py-6 mb-4 bg-[#160e0a] border border-[#2a1a14]">
            <p class="text-[#c9baaf] font-semibold text-lg">{{ __('This request was :status.', ['status' => __($cashTransaction->status === 'expired' ? 'expired' : 'cancelled')]) }}</p>
        </div>
        <a href="{{ route('play.cash.index') }}" class="inline-block rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-6 py-2 shadow-[0_4px_14px_-4px_rgba(220,38,38,0.6)]">{{ __('Back to Cash In / Out') }}</a>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const code = @json($cashTransaction->code);
    const statusUrl = @json(route('play.cash.status', $cashTransaction));

    function showStatus(status) {
        document.getElementById('pending-block').classList.toggle('hidden', status !== 'pending');
        document.getElementById('completed-block').classList.toggle('hidden', status !== 'completed');
        document.getElementById('cancelled-block').classList.toggle('hidden', !['cancelled', 'expired'].includes(status));
    }

    function poll() {
        fetch(statusUrl, { headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(data => showStatus(data.status))
            .catch(() => {});
    }

    // The broadcast already carries the new status directly, so the common
    // case never needs a fetch at all. poll() only runs as a resync when the
    // socket (re)connects, covering a status change that happened while a
    // dropped connection would otherwise have missed it.
    window.addEventListener('echo:ready', () => {
        window.Echo.channel('cash-transaction.' + code)
            .listen('.CashTransactionUpdated', (e) => showStatus(e.status));

        window.onEchoReconnect(poll);
    });
})();
</script>
@endpush
@endsection
