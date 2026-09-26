@extends('layouts.app')

@section('title', __('Ticket :code', ['code' => $bet->ticket_code]))

@php
    $payout = (float) ($bet->payout ?? 0);
    $currency = $bet->fight->event->game?->theme()['currency'] ?? \App\Support\GameTheme::currencySymbol(null);
@endphp

@section('content')
<div class="max-w-sm mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">{{ __('Ticket :code', ['code' => $bet->ticket_code]) }}</h1>
        <a href="{{ route('teller.tickets.create') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Write / redeem another') }}</a>
    </div>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6 text-center">
        <p class="text-2xl font-extrabold uppercase mb-1">{{ $bet->side }}</p>
        <p class="text-3xl font-extrabold text-yellow-400 mb-2">{{ $currency }}{{ number_format($bet->amount, 2) }}</p>
        <p class="text-sm text-slate-400 mb-1">{{ __(':event — Fight #:number', ['event' => $bet->fight->event->name, 'number' => $bet->fight->fight_number]) }}</p>
        <p class="text-xs text-slate-500 mb-4">{{ __('Written :time by :name', ['time' => $bet->created_at->format('M j, Y g:i A'), 'name' => $bet->placedByTeller?->displayName() ?? '—']) }}</p>

        @include('teller.tickets._printable', ['bet' => $bet])

        <button type="button" onclick="printOnly('printable-ticket')" class="mt-4 rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold px-4 py-2 text-sm">🖨️ {{ __('Reprint ticket') }}</button>
    </div>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 text-center">
        @if ($bet->status === 'matched')
            <p class="text-slate-300 font-semibold mb-1">{{ __('Fight not settled yet') }}</p>
            <p class="text-sm text-slate-500">{{ __('Check back once the result is declared.') }}</p>
        @elseif ($payout <= 0)
            <p class="text-red-400 font-semibold mb-1">{{ __('This ticket did not win') }}</p>
            <p class="text-sm text-slate-500">{{ __('Nothing to pay out.') }}</p>
        @elseif ($bet->redeemed_at)
            <p class="text-emerald-400 font-semibold mb-1">{{ __('Already redeemed') }}</p>
            <p class="text-sm text-slate-500 mb-4">{{ __(':amount paid out :time by :name.', ['amount' => $currency.number_format($payout, 2), 'time' => $bet->redeemed_at->format('M j, Y g:i A'), 'name' => $bet->redeemedByTeller?->displayName() ?? '—']) }}</p>

            @include('teller.tickets._payout_receipt', ['bet' => $bet])

            <button type="button" onclick="printOnly('printable-receipt')" class="mt-4 rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold px-4 py-2 text-sm">🖨️ {{ __('Print payout receipt') }}</button>
        @else
            <p class="text-emerald-400 font-extrabold text-lg mb-1">{{ __('WINNER — :amount', ['amount' => $currency.number_format($payout, 2)]) }}</p>
            <p class="text-sm text-slate-500 mb-4">{{ __('Verify the physical ticket matches, then pay out and mark it redeemed.') }}</p>
            <form method="POST" action="{{ route('teller.tickets.redeem', $bet) }}" onsubmit="return confirm('{{ __('Pay out :amount for this ticket?', ['amount' => $currency.number_format($payout, 2)]) }}');">
                @csrf
                <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold py-2">{{ __('Pay Out :amount', ['amount' => $currency.number_format($payout, 2)]) }}</button>
            </form>
        @endif
    </div>
</div>

@push('scripts')
<script>
    // This page can hold two printable slips at once — the original claim
    // ticket and (once redeemed) a payout receipt. Only one should ever go
    // to the printer per click: mark the requested one .print-active and
    // clear that flag from any other .printable so the print CSS (which
    // only reveals .print-active) doesn't render both stacked together.
    function printOnly(id) {
        document.querySelectorAll('.printable').forEach(function (el) {
            el.classList.toggle('print-active', el.id === id);
        });
        window.print();
    }

    @if (request()->query('paid'))
        // Landed here straight off the "Pay Out" action (?paid=1 on this
        // exact redirect — see TicketController::redeem()) — auto-print
        // the payout receipt the same way the write-ticket flow auto-
        // prints the claim ticket, so the teller doesn't have to click
        // "Print payout receipt" separately. Reloading or revisiting this
        // same ticket later has no ?paid=1 in the URL, so it won't
        // reprint on its own.
        window.addEventListener('load', function () {
            setTimeout(function () { printOnly('printable-receipt'); }, 150);
        });
    @endif
</script>
@endpush
@endsection
