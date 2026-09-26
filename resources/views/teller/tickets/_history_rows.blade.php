{{-- Rendered both on initial page load and on every status poll (see
     create.blade.php + TicketController::status()), so a void or a ticket
     written at another window shows up here without a reload. --}}
@forelse ($history as $bet)
    @php
        $sideLabel = $bet->fight->event->sideLabel($bet->side);
        $currency = $bet->fight->event->game?->theme()['currency'] ?? \App\Support\GameTheme::currencySymbol(null);
        $statusBadge = match (true) {
            $bet->status === 'voided' => [__('Voided'), 'bg-slate-700 text-slate-400 line-through'],
            $bet->status === 'matched' => [__('Open'), 'bg-sky-900 text-sky-300'],
            $bet->status === 'settled' && (float) $bet->payout <= 0 => [__('Lost'), 'bg-slate-800 text-slate-400'],
            $bet->status === 'settled' && $bet->redeemed_at => [__('Paid'), 'bg-slate-800 text-slate-400'],
            $bet->status === 'settled' => [__('Won — unclaimed'), 'bg-emerald-900 text-emerald-300'],
            default => [ucfirst($bet->status), 'bg-slate-800 text-slate-400'],
        };
    @endphp
    <tr class="border-b border-slate-800/50" data-ticket-code="{{ $bet->ticket_code }}">
        <td class="py-1.5 pr-2 text-slate-400">#{{ $bet->fight->fight_number }}</td>
        <td class="pr-2 capitalize">{{ $sideLabel }}</td>
        <td class="pr-2">{{ $currency }}{{ number_format($bet->amount, 0) }}</td>
        <td class="pr-2">
            <span class="text-[10px] px-1.5 py-0.5 rounded-full font-bold uppercase {{ $statusBadge[1] }}">{{ $statusBadge[0] }}</span>
        </td>
        <td class="pr-2 font-mono text-[11px] text-slate-500">{{ $bet->ticket_code }}</td>
        <td class="text-right whitespace-nowrap">
            <button type="button" class="history-print-btn text-slate-400 hover:text-white transition text-xs px-1.5" data-code="{{ $bet->ticket_code }}" title="{{ __('Reprint') }}">🖨️</button>
            @if ($bet->isVoidable())
                <button type="button" class="history-void-btn text-red-400 hover:text-red-300 transition text-xs px-1.5" data-code="{{ $bet->ticket_code }}" title="{{ __('Void') }}">{{ __('Void') }}</button>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" class="py-4 text-center text-slate-500">{{ __('No tickets written for this event yet.') }}</td>
    </tr>
@endforelse
