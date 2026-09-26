{{-- Shared by receipt.blade.php and show.blade.php so the printed ticket
     looks the same whether it's printed right after writing it or reprinted
     later from a lookup. Kept on a white background (unlike the rest of the
     dark UI) since this is a stand-in for the physical paper ticket. --}}
@php
    $event = $bet->fight->event;
    $sideLabel = $event->sideLabel($bet->side);
    $currency = $event->game?->theme()['currency'] ?? \App\Support\GameTheme::currencySymbol(null);
@endphp
{{-- print-active by default: pages that only ever show this one printable
     (write-ticket, reprint) need no JS to make their plain window.print()
     button work. show.blade.php, which can also show a payout receipt
     alongside this, switches it on/off itself via printOnly(). --}}
<div id="printable-ticket" class="printable print-active bg-white text-slate-900 rounded-xl p-4 text-center mx-auto max-w-xs">
    <p class="text-slate-900 font-extrabold text-sm uppercase tracking-wide mb-0.5">{{ $event->game?->display_name ?? __('Pool Sabong') }}</p>
    <p class="text-slate-500 text-xs mb-3">{{ $event->name }} &middot; {{ __('Fight #:number', ['number' => $bet->fight->fight_number]) }}</p>

    <div class="inline-block mb-3">
        {{-- QrCodeGenerator adds its 10px margin on top of $size, so 180
             here renders as an actual 200x200 SVG. --}}
        {!! \App\Support\QrCodeGenerator::svg(route('teller.tickets.show', $bet), 180) !!}
    </div>

    <p class="text-black text-xl font-extrabold uppercase mb-1">{{ strtoupper($sideLabel) }}</p>
    <p class="text-black text-2xl font-extrabold mb-2">{{ $currency }}{{ number_format($bet->amount, 2) }}</p>

    <p class="text-slate-900 text-sm font-mono tracking-widest mb-1">{{ $bet->ticket_code }}</p>
    <p class="text-slate-500 text-[11px] mb-0.5">{{ $bet->created_at->format('M j, Y g:i A') }}</p>
    <p class="text-slate-500 text-[11px]">{{ __('Written by :name', ['name' => $bet->placedByTeller?->displayName() ?? '—']) }}</p>

    <p class="text-slate-500 text-[10px] mt-3 border-t border-dashed border-slate-300 pt-2">
        {{ __('Present this ticket at any teller window to claim winnings. Non-transferable.') }}
    </p>
</div>
