{{-- Everything that changes as fights progress — re-rendered wholesale and
     swapped in by the parent view's refreshPanel() whenever a fight status
     changes anywhere in this event, instead of reloading the page. --}}
@php
    // CombinedSabong routes declare/cancel/redeclare through its own
    // settlement service — every other game keeps today's routes
    // unchanged. Nothing else on this panel (open/last-call/close/
    // toggle-draw/cockpit/fight-number/bets) differs by game type.
    $isCombined = $event->game?->isCombined() ?? false;
    $closeRouteName = $isCombined ? 'declarator.combined-fights.close' : 'declarator.fights.close';
    $declareRouteName = $isCombined ? 'declarator.combined-fights.declare' : 'declarator.fights.declare';
    $cancelRouteName = $isCombined ? 'declarator.combined-fights.cancel' : 'declarator.fights.cancel';
    $redeclareRouteName = $isCombined ? 'declarator.combined-fights.redeclare' : 'declarator.fights.redeclare';
@endphp
@if ($fights->isEmpty())
    <p class="text-slate-500">{{ __('No fights in progress. Start the event, or use "Start next fight" above, to create one.') }}</p>
@else
    <div class="grid lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            @foreach ($fights as $row)
                @php [$fight, $poolTotals, $payouts] = [$row['fight'], $row['poolTotals'], $row['payouts']]; @endphp
                <div class="fight-card rounded-xl border border-slate-800 bg-slate-900 p-4"
                     data-fight-id="{{ $fight->id }}" data-status="{{ $fight->status }}"
                     data-bets-url="{{ route('declarator.fights.bets', $fight) }}">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-semibold">{{ __('Fight #:number', ['number' => $fight->fight_number]) }}</h2>
                        @php
                            $statusBadgeClass = match ($fight->status) {
                                'open' => 'bg-emerald-600',
                                'last_call' => 'bg-yellow-500 text-slate-900',
                                'closed' => 'bg-amber-600',
                                default => 'bg-slate-700',
                            };
                            // strtoupper() alone would show "LAST_CALL" (the
                            // raw DB value's underscore) instead of "LAST CALL".
                            $statusBadgeLabel = $fight->status === 'last_call' ? __('LAST CALL') : __(strtoupper($fight->status));
                        @endphp
                        <span class="fight-status-badge text-xs px-3 py-1 rounded-full font-semibold {{ $statusBadgeClass }}">
                            {{ $statusBadgeLabel }}
                        </span>
                    </div>

                    @if ($fight->status !== 'pending' && $cockpits->isNotEmpty())
                        {{-- Reassignable after opening too — the radios on the
                             open-bets form below only ever apply once, and two
                             fights running in parallel need genuinely different
                             cockpits, so a mistake here (e.g. both left on the
                             same ring) has to be fixable without cancelling
                             either fight. --}}
                        <form data-ajax method="POST" action="{{ route('declarator.fights.cockpit', $fight) }}" class="flex items-center gap-2 mb-2 text-xs">
                            @csrf
                            <span class="text-slate-500">📹</span>
                            <select name="cockpit_id" class="bg-slate-800 border border-slate-700 rounded px-2 py-1 text-xs">
                                <option value="">{{ __('None') }}</option>
                                @foreach ($cockpits as $cockpit)
                                    @php $heldByFightId = $busyCockpits[$cockpit->id] ?? null; @endphp
                                    @php $isBusy = $heldByFightId && $heldByFightId !== $fight->id; @endphp
                                    <option value="{{ $cockpit->id }}" {{ $fight->cockpit_id === $cockpit->id ? 'selected' : '' }} {{ $isBusy ? 'disabled' : '' }}>
                                        {{ $cockpit->name }}{{ $isBusy ? ' ('.__('in use').')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <button class="rounded-lg bg-slate-700 hover:bg-slate-600 transition text-xs px-3 py-1">{{ __('Update') }}</button>
                        </form>
                    @endif

                    @if ($fight->status === 'closed')
                        <p class="text-amber-400 text-xs mb-3">{{ __('Awaiting declaration.') }}</p>
                    @endif

                    <div class="grid grid-cols-3 gap-3 mb-4 text-center text-sm">
                        <div class="rounded-lg bg-red-950/30 border border-red-800 py-2">
                            <p class="{{ $theme['meron']['soft_text'] }} text-xs">{{ $event->label_meron }}</p>
                            <p class="font-bold pool-meron">{{ $theme['currency'] }}{{ number_format($poolTotals['meron'], 2) }}</p>
                        </div>
                        <div class="rounded-lg bg-sky-950/30 border border-sky-800 py-2">
                            <p class="{{ $theme['wala']['soft_text'] }} text-xs">{{ $event->label_wala }}</p>
                            <p class="font-bold pool-wala">{{ $theme['currency'] }}{{ number_format($poolTotals['wala'], 2) }}</p>
                        </div>
                        <div class="rounded-lg bg-emerald-950/30 border border-emerald-800 py-2">
                            <p class="text-emerald-300 text-xs">{{ $event->label_draw }}</p>
                            <p class="font-bold pool-draw">{{ $theme['currency'] }}{{ number_format($poolTotals['draw'], 2) }}</p>
                        </div>
                    </div>

                    @if ($isCombined && ($row['oddsTierTotals'] ?? collect())->isNotEmpty())
                        <div class="mb-4 rounded-lg border border-slate-800 overflow-hidden text-xs">
                            <div class="grid grid-cols-3 bg-black/30 px-2 py-1 font-semibold text-slate-400">
                                <span>{{ __('Meron') }}</span>
                                <span>{{ __('Tier') }}</span>
                                <span>{{ __('Wala') }}</span>
                            </div>
                            @foreach ($row['oddsTierTotals'] as $tierId => $totals)
                                <div class="grid grid-cols-3 px-2 py-1 border-t border-slate-800">
                                    <span class="text-red-400">{{ $theme['currency'] }}{{ number_format($totals['meron'], 2) }}</span>
                                    <span class="text-slate-300">{{ $oddsTierLabels[$tierId] ?? $tierId }}</span>
                                    <span class="text-sky-400">{{ $theme['currency'] }}{{ number_format($totals['wala'], 2) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2 fight-controls">
                        <form data-ajax method="POST" action="{{ route('declarator.fights.open', $fight) }}" class="fight-action w-full space-y-2" data-visible-when="pending">
                            @csrf
                            @if ($cockpits->isNotEmpty())
                                @php
                                    // Pre-select this fight's own cockpit if it already
                                    // has one (e.g. re-declaring); otherwise default to
                                    // the first cockpit that isn't already busy, so a
                                    // fresh fight never opens with nothing chosen. Still
                                    // just a default, not a lock — the declarator can
                                    // pick a different free cockpit before opening bets,
                                    // which is what actually keeps two parallel fights
                                    // off the same ring (busy ones stay disabled below).
                                    $defaultCockpitId = $fight->cockpit_id
                                        ?? $cockpits->first(fn ($c) => ! ($busyCockpits[$c->id] ?? null))?->id;
                                @endphp
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($cockpits as $cockpit)
                                        @php $heldByFightId = $busyCockpits[$cockpit->id] ?? null; @endphp
                                        {{-- A cockpit already holding another pending/open/closed fight
                                             (in this event or any other) is greyed out and unselectable —
                                             two fights sharing one ring means two declarators fighting over
                                             the same camera feed. Doesn't apply to this fight's own cockpit
                                             (e.g. re-picking it after a mistake elsewhere). --}}
                                        @php $isBusy = $heldByFightId && $heldByFightId !== $fight->id; @endphp
                                        <label class="flex items-center gap-1.5 text-xs bg-slate-800 border border-slate-700 rounded-full px-3 py-1.5
                                            {{ $isBusy ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-950/40' }}">
                                            <input type="radio" name="cockpit_id" value="{{ $cockpit->id }}" required class="accent-emerald-500"
                                                {{ $defaultCockpitId === $cockpit->id ? 'checked' : '' }}
                                                {{ $isBusy ? 'disabled' : '' }}>
                                            {{ $cockpit->name }}
                                            @if ($isBusy)
                                                <span class="text-slate-500">({{ __('in use') }})</span>
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                            <button class="rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold px-4 py-2 text-sm">{{ __('Open bets') }}</button>
                        </form>
                        <form data-ajax method="POST" action="{{ route('declarator.fights.last-call', $fight) }}" class="fight-action" data-visible-when="open">
                            @csrf
                            <button class="rounded-lg bg-yellow-500 hover:bg-yellow-400 text-slate-900 transition font-semibold px-4 py-2 text-sm">{{ __('Last call') }}</button>
                        </form>
                        {{-- Gated behind Last call — never shown on a merely
                             'open' fight, so betting can't be cut short
                             without that warning stage first. --}}
                        <form data-ajax method="POST" action="{{ route($closeRouteName, $fight) }}" class="fight-action" data-visible-when="last_call">
                            @csrf
                            <button class="rounded-lg bg-amber-600 hover:bg-amber-500 transition font-semibold px-4 py-2 text-sm">{{ __('Close bets') }}</button>
                        </form>
                        <form data-ajax method="POST" action="{{ route($declareRouteName, $fight) }}" class="fight-action" data-visible-when="closed">
                            @csrf
                            <input type="hidden" name="winner" value="meron">
                            <button class="rounded-lg {{ $theme['meron']['declare_btn'] }} transition font-semibold px-4 py-2 text-sm">{{ __('Declare :side', ['side' => $event->label_meron]) }}</button>
                        </form>
                        <form data-ajax method="POST" action="{{ route($declareRouteName, $fight) }}" class="fight-action" data-visible-when="closed">
                            @csrf
                            <input type="hidden" name="winner" value="wala">
                            <button class="rounded-lg {{ $theme['wala']['declare_btn'] }} transition font-semibold px-4 py-2 text-sm">{{ __('Declare :side', ['side' => $event->label_wala]) }}</button>
                        </form>
                        {{-- Always available to the declarator regardless of draw_enabled —
                             that flag only controls whether players could bet on a draw, not
                             whether the fight can actually end in one. --}}
                        <form data-ajax method="POST" action="{{ route($declareRouteName, $fight) }}" class="fight-action" data-visible-when="closed">
                            @csrf
                            <input type="hidden" name="winner" value="draw">
                            <button class="rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold px-4 py-2 text-sm">{{ __('Declare :side', ['side' => $event->label_draw]) }}</button>
                        </form>
                        <form data-ajax data-confirm="{{ __('Cancel this fight and refund all bets?') }}" method="POST" action="{{ route($cancelRouteName, $fight) }}" class="fight-action" data-visible-when="pending,open,last_call,closed">
                            @csrf
                            <button class="rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold px-4 py-2 text-sm">{{ __('Cancel fight') }}</button>
                        </form>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-4 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-slate-500">{{ __('Draw betting:') }}</span>
                            <form data-ajax method="POST" action="{{ route('declarator.fights.toggle-draw', $fight) }}">
                                @csrf
                                <button class="text-red-400 hover:underline">{{ $fight->draw_enabled ? __('Disable') : __('Enable') }}</button>
                            </form>
                        </div>
                        <form data-ajax method="POST" action="{{ route('declarator.fights.fight-number', $fight) }}" class="flex items-center gap-2">
                            @csrf
                            <span class="text-slate-500">{{ __('Fight number:') }}</span>
                            <input type="number" name="fight_number" value="{{ $fight->fight_number }}" min="1"
                                   class="w-20 rounded-lg bg-slate-800 border border-slate-700 px-2 py-1 text-sm">
                            <button class="rounded-lg bg-slate-700 hover:bg-slate-600 transition text-xs px-3 py-1">{{ __('Update') }}</button>
                        </form>
                    </div>

                    <div class="mt-4">
                        <h3 class="text-sm font-semibold mb-2 text-slate-400">{{ __('Live bettors') }}</h3>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-slate-500 border-b border-slate-800">
                                    <th class="py-1">{{ __('Player') }}</th>
                                    <th>{{ __('Side') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Time') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bettor-table"></tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <h2 class="font-semibold mb-3">{{ __('Recent fights') }}</h2>
                <div class="space-y-2 text-sm">
                    @forelse ($fightHistory as $past)
                        <div class="flex items-center justify-between">
                            <span>#{{ $past->fight_number }}</span>
                            <span class="capitalize">{{ $past->winner ? $event->sideLabel($past->winner) : __('cancelled') }}</span>
                            @if ($past->status === 'declared')
                                <form data-ajax data-confirm="{{ __('Re-declare this fight? Existing payouts will be reversed.') }}" method="POST" action="{{ route($redeclareRouteName, $past) }}" class="redeclare-form">
                                    @csrf
                                    <select name="winner" class="bg-slate-800 border border-slate-700 rounded px-1 py-0.5 text-xs">
                                        <option value="meron">{{ $event->label_meron }}</option>
                                        <option value="wala">{{ $event->label_wala }}</option>
                                        <option value="draw">{{ $event->label_draw }}</option>
                                        <option value="cancelled">{{ __('Cancel') }}</option>
                                    </select>
                                    <button class="text-xs text-red-400 hover:underline ml-1">{{ __('Fix') }}</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-slate-500">{{ __('No fights settled yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endif
