{{-- Printed proof of payout, handed to the bettor after a winning counter
     ticket is redeemed for cash. Separate slip from the original claim
     ticket (_printable.blade.php) — a teller can still reprint that ticket
     from the same page independently of printing this receipt. Only
     rendered once the bet is actually redeemed. --}}
@php
    $event = $bet->fight->event;
    $sideLabel = $event->sideLabel($bet->side);
    $currency = $event->game?->theme()['currency'] ?? \App\Support\GameTheme::currencySymbol(null);
@endphp
<div id="printable-receipt" class="printable bg-white text-slate-900 rounded-xl p-4 text-center mx-auto max-w-xs">
    <p class="text-slate-900 font-extrabold text-sm uppercase tracking-wide mb-0.5">{{ $event->game?->display_name ?? __('Pool Sabong') }}</p>
    <p class="text-slate-500 text-xs mb-3 uppercase tracking-wide">{{ __('Payout Receipt') }}</p>

    <p class="text-slate-500 text-xs mb-1">{{ $event->name }} &middot; {{ __('Fight #:number', ['number' => $bet->fight->fight_number]) }}</p>
    <p class="text-black text-lg font-extrabold uppercase mb-1">{{ strtoupper($sideLabel) }}</p>
    <p class="text-slate-500 text-xs mb-3">{{ __('Staked :amount', ['amount' => $currency.number_format($bet->amount, 2)]) }}</p>

    <p class="text-black text-3xl font-extrabold mb-1">{{ $currency }}{{ number_format($bet->payout, 2) }}</p>
    <p class="text-emerald-700 text-xs font-bold uppercase tracking-wide mb-3">{{ __('Paid Out') }}</p>

    <p class="text-slate-900 text-sm font-mono tracking-widest mb-1">{{ $bet->ticket_code }}</p>
    <p class="text-slate-500 text-[11px] mb-0.5">{{ optional($bet->redeemed_at)->format('M j, Y g:i A') }}</p>
    <p class="text-slate-500 text-[11px]">{{ __('Paid by :name', ['name' => $bet->redeemedByTeller?->displayName() ?? '—']) }}</p>

    <p class="text-slate-500 text-[10px] mt-3 border-t border-dashed border-slate-300 pt-2">
        {{ __('This receipt is your proof of payment. The ticket itself is void.') }}
    </p>
</div>
