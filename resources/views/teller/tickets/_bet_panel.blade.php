{{-- The event/fight card — re-rendered server-side and swapped in whenever
     the open fight itself changes (closes, a new one opens, the event
     ends), instead of just patching pool numbers in place. See create.blade
     .php's setupBetPanel()/applyPoolData() for when this gets swapped vs.
     left alone (typing an amount mid-poll must survive a same-fight tick). --}}
@if (! $event)
    <p class="text-slate-500 text-sm text-center py-6">{{ __('No active event right now — ask the declarator to start one.') }}</p>
@elseif (! $fight)
    <p class="text-slate-500 text-sm text-center py-6">{{ __('Betting is not open for :event right now.', ['event' => $event->name]) }}</p>
@else
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <p class="font-semibold text-sm">{{ $event->name }}</p>
            <p class="font-bold text-sm text-yellow-400">{{ __('Fight #:number', ['number' => $fight->fight_number]) }}</p>
        </div>
        <span class="text-[11px] px-2.5 py-0.5 rounded-full font-bold uppercase bg-emerald-600 text-white">{{ __('OPEN') }}</span>
    </div>

    <form method="POST" action="{{ route('teller.tickets.store') }}" id="ticket-form" class="space-y-3">
        @csrf
        <input type="hidden" name="side" id="ticket-side-input" value="{{ old('side') }}">

        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Amount') }}</label>
            <input type="text" inputmode="numeric" name="amount" id="ticket-amount-input" data-decimals="0" required value="{{ old('amount') }}"
                   class="amount-input w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
        </div>

        <div class="grid grid-cols-2 gap-1 rounded-xl overflow-hidden">
            <button type="submit" data-side="meron" class="ticket-side-btn {{ $theme['meron']['panel'] }} {{ $theme['meron']['btn'] }} transition p-5 text-center disabled:opacity-50">
                <span class="block text-yellow-400 font-extrabold tracking-wide text-lg">{{ strtoupper($event->label_meron) }}</span>
                <span class="block text-white font-semibold text-sm mt-1" id="meron-pool">{{ $theme['currency'] }}{{ number_format($meronPool, 2) }}</span>
                <span class="block text-white/80 text-xs">{{ __('PAYOUT') }} <span id="meron-payout-pct">{{ number_format($payouts['meron_pct'], 2) }}</span>%</span>
            </button>
            <button type="submit" data-side="wala" class="ticket-side-btn {{ $theme['wala']['panel'] }} {{ $theme['wala']['btn'] }} transition p-5 text-center disabled:opacity-50">
                <span class="block text-yellow-400 font-extrabold tracking-wide text-lg">{{ strtoupper($event->label_wala) }}</span>
                <span class="block text-white font-semibold text-sm mt-1" id="wala-pool">{{ $theme['currency'] }}{{ number_format($walaPool, 2) }}</span>
                <span class="block text-white/80 text-xs">{{ __('PAYOUT') }} <span id="wala-payout-pct">{{ number_format($payouts['wala_pct'], 2) }}</span>%</span>
            </button>
        </div>

        @if ($fight->draw_enabled)
            <button type="submit" data-side="draw" class="ticket-side-btn w-full rounded-lg bg-teal-700 hover:bg-teal-600 transition font-bold py-2 text-sm disabled:opacity-50">
                {{ __('BET :side', ['side' => strtoupper($event->label_draw)]) }} &middot; <span id="draw-pool">{{ $theme['currency'] }}{{ number_format($drawPool, 2) }}</span> &middot; {{ __('pays x:multiplier', ['multiplier' => number_format($drawMultiplier, 0)]) }}
            </button>
        @endif
    </form>
@endif
