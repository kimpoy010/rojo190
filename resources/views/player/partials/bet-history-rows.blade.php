<div id="bet-history-list" class="flex flex-col gap-2 text-sm">
    @forelse ($myBetHistory as $bet)
        @php
            $theme = $bet->fight->event->game?->theme() ?? \App\Support\GameTheme::for(null);
            $sideBadge = match ($bet->side) {
                'meron' => ['bg' => 'rgba(220,38,38,0.18)', 'text' => '#fca5a5'],
                'wala' => $theme['wala']['hex'] === '#16a34a'
                    ? ['bg' => 'rgba(22,163,74,0.18)', 'text' => '#86efac']
                    : ['bg' => 'rgba(37,99,235,0.18)', 'text' => '#93c5fd'],
                default => ['bg' => 'rgba(15,118,110,0.25)', 'text' => '#5eead4'],
            };
            $historyStatus = $bet->historyStatusLabel();
        @endphp
        <div class="rounded-xl p-3" style="background:#0b0705;border:1px solid #2a1a14;">
            <div class="flex items-center justify-between gap-2">
                <span class="inline-block text-[10px] font-extrabold tracking-wide px-2 py-0.5 rounded-md uppercase" style="background:{{ $sideBadge['bg'] }};color:{{ $sideBadge['text'] }};">
                    {{ $bet->fight->event->sideLabel($bet->side) }}
                </span>
                <span class="text-[11px] font-bold {{ $historyStatus['class'] }}">{{ $historyStatus['label'] }}</span>
            </div>
            <p class="font-semibold text-sm mt-2 truncate">{{ $bet->fight->event->name }}</p>
            <p class="text-[11.5px] text-[#8a7a70]">{{ __('Fight #:number', ['number' => $bet->fight->fight_number]) }} &middot; {{ $bet->created_at->format('M j, g:i A') }}</p>
            <div class="flex items-center justify-between mt-2">
                <span class="text-[11px] text-[#8a7a70]">{{ __('Stake') }}</span>
                <span class="text-sm font-extrabold text-amber-400">{{ $theme['currency'] }}{{ number_format($bet->amount, 2) }}</span>
            </div>
        </div>
    @empty
        <p class="text-[#8a7a70]">{{ __('No bets placed yet.') }}</p>
    @endforelse
</div>

@if ($myBetHistory->hasPages())
    <div class="mt-3 text-xs">
        {{ $myBetHistory->onEachSide(1)->links() }}
    </div>
@endif
