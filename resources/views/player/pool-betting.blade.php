@extends('layouts.app')

@section('title', __('Fight #:number — :event', ['number' => $fight->fight_number, 'event' => $event->name]))

@php
    $statusCopyMap = [
        'pending' => ['label' => __('PENDING'), 'desc' => __('Betting is currently closed.')],
        'open' => ['label' => __('OPEN'), 'desc' => __('Betting is now open — place your bets!')],
        'last_call' => ['label' => __('LAST CALL'), 'desc' => __('Betting closes very soon — get your bets in!')],
        'closed' => ['label' => __('CLOSED'), 'desc' => __('Betting is closed. Waiting for the result...')],
        'declared' => ['label' => __('DECLARED'), 'desc' => __('Result declared.')],
        'cancelled' => ['label' => __('CANCELLED'), 'desc' => __('This fight was cancelled — bets refunded.')],
    ];
    $statusCopy = $statusCopyMap[$fight->status] ?? ['label' => strtoupper($fight->status), 'desc' => ''];

    // Mirrored in JS as statusBadgeClass() below (applyStatus needs the
    // same mapping for a live status change, not just the initial render).
    $statusBadgeClass = fn (string $status) => match ($status) {
        'open' => 'bg-emerald-600 text-white',
        'last_call' => 'bg-yellow-500 text-slate-900',
        'closed' => 'bg-amber-600 text-white',
        default => 'bg-slate-700 text-slate-200',
    };

    // The event switcher's "Fight #:number - STATUS" line below each
    // event's title (see its markup further down) — just the status word's
    // own text color, not a background, so it stays legible against a
    // narrow, equal-width tab. Mirrored in JS as eventTabStatusClass()
    // below for the same reason statusBadgeClass() is.
    $eventTabStatusClass = fn (?string $status) => match ($status) {
        'open' => 'text-emerald-400',
        'last_call' => 'text-yellow-400',
        'closed' => 'text-red-400',
        default => 'text-slate-400',
    };

    $drawRemaining = max(0, $maxDrawBet - $drawPool);
    $meronPayoutPct = $payouts['meron_pct'];
    $walaPayoutPct = $payouts['wala_pct'];
    $theme = ($game ?? null)?->theme() ?? \App\Support\GameTheme::for(null);

    // The Meron/Wala cards' border glow and button shadow are plain
    // inline rgba(), not Tailwind arbitrary-value classes — those get
    // scanned out of the compiled CSS at build time, so a class built
    // from a runtime hex (shadow-[...rgba({{ $x }}...)]) would silently
    // never render for whichever region wasn't in the source literally.
    // GameTheme's 'hex' is already region-correct (blue for Philippines'
    // Wala, green for Mexico's Verde); this just decimal-izes it.
    $hexToRgb = fn (string $hex) => implode(',', sscanf($hex, '#%02x%02x%02x'));
    $meronRgb = $hexToRgb($theme['meron']['hex']);
    $walaRgb = $hexToRgb($theme['wala']['hex']);

    // Handed to the polling/Echo script below so live status updates show
    // in the same language as the initial page render.
    $i18n = [
        'statusCopy' => collect($statusCopyMap)->map(fn ($c) => [$c['label'], $c['desc']]),
        'leftOfPool' => __(':remaining left of :max pool'),
        'cancel' => __('Cancel'),
        'couldNotPlaceBet' => __('Could not place bet'),
        'somethingWrong' => __('Something went wrong.'),
        'networkError' => __('Network error'),
        'couldNotReach' => __('Could not reach the server. Please try again.'),
        'cancelledRefunded' => __('Cancelled — bets refunded'),
        'wins' => __(':side wins!'),
        'fightNumberLabel' => __('Fight #:number'),
        'goToThisFight' => __('Go to this fight'),
        'newFightTitle' => __('A new fight has started'),
        'newFightHtml' => __('Fight #:number has started while this fight awaits declaration.'),
        'proceedToNextFight' => __('Proceed to next fight'),
        'confirmBet' => __('Confirm your bet'),
        'confirmBetText' => __(':amount on :side?'),
        'betPlaced' => __('Bet placed'),
        'betPlacedSuccess' => __('Bet placed successfully.'),
        'betPlacedDetail' => __(':amount on :side'),
        'enterAmount' => __('Enter amount'),
        'chooseAmountText' => __('How much do you want to bet on :side?'),
        'invalidAmount' => __('Enter a valid amount.'),
        'amountPlaceholder' => __('Amount'),
    ];
@endphp

@section('content')
{{-- Full-bleed on mobile (this page is what most players use, phone-first) —
     escapes <main>'s px-4 gutter via a viewport-relative breakout so there's
     no dead space on the sides; from sm: up it reverts to the normal
     centered, padded column so desktop is unchanged. -mt-6 cancels out
     <main>'s py-6 top padding on mobile too, so the video sits right under
     the nav instead of leaving a big gap above it. --}}
<div class="w-screen mx-[calc(50%-50vw)] -mt-6 sm:w-full sm:max-w-lg sm:mx-auto sm:mt-0 space-y-1">

    {{-- $mainStreamUrl comes from the controller: this fight's own cockpit
         feed takes priority, then the event's cockpit-preset primary, then
         (so the video never goes blank during the gap after a declare)
         whichever cockpit this event most recently finished using. See
         PoolBetController::mainStreamUrl(). --}}

    @if ($videoEnabled)
        <!-- Live stream — sized to this column like every other section here
             (not full-bleed: on a wide-but-short window a full-viewport-width
             16:9 box gets tall enough to swallow the whole screen), and sticky
             under the nav so it stays visible while the rest of this column
             scrolls underneath it. -->
        {{-- Hidden (not omitted) when there's no stream yet — a fight can go
             from no-cockpit to cockpit-assigned without a page reload (the
             declarator opens bets and picks a cockpit while this page is
             already sitting here), so this has to stay in the DOM either way
             for the live-update script below to reveal and populate once one
             becomes available, same pattern as #pip-stack. --}}
        <div id="live-stream" class="sticky top-14 z-30 aspect-video rounded-xl overflow-hidden border border-slate-800 bg-black" @if (! $mainStreamUrl) hidden @endif>
            <iframe id="live-stream-iframe" src="{{ $mainStreamUrl }}" class="w-full h-full" frameborder="0"
                allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"></iframe>
        </div>

        {{-- Floating panel for every OTHER fight still live in this event (an
             older one awaiting declaration, a newer one already under way on
             another ring) — one tile per fight, stacked flush against each
             other and the header with zero gap, reading as one seamless panel
             instead of a pile of separately-framed videos (all border/rounding/
             shadow live on this outer wrapper only, never on a tile itself).
             Fixed to the viewport (not the video box) so it's draggable
             anywhere on screen, not just within the main video's bounds; see
             setupPip() below. Present even when this fight has no stream of
             its own ($mainStreamUrl is empty) since the other fights' videos
             are independent of it. Hidden by default — populated and shown by
             applyPip() below the moment there's something to show, and kept in
             the DOM either way so the live-update script always has it. --}}
        <div id="pip-stack" class="group fixed z-[45] top-20 right-2 w-32 sm:w-40 rounded-lg overflow-hidden border border-slate-700 shadow-xl cursor-move touch-none select-none" hidden>
            {{-- Hidden until the panel is hovered (desktop) or tapped (see the
                 pip-header-peek toggle in setupPip() below, needed since touch
                 has no hover state) — overlaid on top of the tiles via
                 position:absolute (pip-stack's own `fixed` already gives it a
                 containing block) rather than pushed above them, so there's
                 nothing reserved for it, and thus nothing to show, the rest of
                 the time. --}}
            <div id="pip-header" class="absolute inset-x-0 top-0 z-10 flex items-center justify-between bg-black/70 px-2 py-1 opacity-0 pointer-events-none transition-opacity duration-150 group-hover:opacity-100 group-hover:pointer-events-auto">
                <span class="text-[10px] font-bold text-white uppercase tracking-wide">{{ __('Other fights') }}</span>
                <button type="button" id="pip-close" class="w-5 h-5 rounded-full bg-black/40 hover:bg-black/70 text-white text-xs leading-none flex items-center justify-center shrink-0">&times;</button>
            </div>
            <div id="pip-tiles" class="flex flex-col max-h-[70vh] overflow-y-auto scroll-thin"></div>
        </div>
    @endif

    <script>
        // #site-nav (the top bar) doesn't exist at all for a player —
        // that's the whole point of removing it — so there's nothing to
        // sit below and both offsets are just 0. Where it does exist (every
        // other role), its height isn't fixed — it wraps to a second line
        // on narrow screens once the language switcher/user menu no longer
        // fit beside the logo — so a hardcoded offset would let its bottom
        // edge cover the video (or the PiP panel's starting position).
        // Measure the real height instead of guessing. The PiP panel only
        // follows this on resize until the player drags it themselves —
        // after that a resize shouldn't yank a manually-placed panel back
        // under the nav.
        (function () {
            const nav = document.getElementById('site-nav');
            const stream = document.getElementById('live-stream');
            const pipStack = document.getElementById('pip-stack');

            function syncTop() {
                const navHeight = nav ? nav.getBoundingClientRect().height : 0;
                if (stream) stream.style.top = navHeight + 'px';
                if (pipStack && !pipStack.dataset.userMoved) pipStack.style.top = (navHeight + 8) + 'px';
            }

            syncTop();
            window.addEventListener('resize', syncTop);
        })();
    </script>

    <style>
        /* Highlights whichever preset amount chip was last clicked, so a
           player can see which one filled the amount field (chip-btn has no
           other selected/pressed state of its own). Cleared on manual typing
           in #bet-amount — see the input listener below. */
        .chip-btn-active {
            background-color: #dc2626 !important;
            border-color: #dc2626 !important;
            color: #fff !important;
            box-shadow: 0 4px 14px -4px rgba(220, 38, 38, 0.65);
        }

        /* A fight-switcher tab whose fight just got declared/cancelled
           stays in the bar for a brief rapid-flash window (see renderTabs/
           markFightTabResolved below, which removes it once that flash
           ends — RESOLVED_FLASH_MS there must match this animation's own
           total run time) and glows in the winning side's own color
           instead of just vanishing outright. Two custom properties are
           set inline per tab (winner color varies per fight, not
           something a fixed class can express): --tab-glow-solid for a
           permanent, always-visible border (the actual "which side won"
           signal — box-shadow alone was too subtle to read against a
           tightly-packed, low-contrast tab bar), and --tab-glow-soft
           (same color, translucent) for the fast pulse on top of it. */
        #fight-tabs a[data-resolved="1"] {
            border: 2px solid var(--tab-glow-solid, transparent);
            animation: fight-tab-glow 0.4s ease-in-out 7.5;
        }
        @keyframes fight-tab-glow {
            0%, 100% { box-shadow: 0 0 6px 2px var(--tab-glow-soft, transparent); }
            50% { box-shadow: 0 0 16px 5px var(--tab-glow-soft, transparent); }
        }

        /* Same winner-colored flash as a resolved fight-switcher tab above,
           applied to that fight's own PiP tile (see markFightTabResolved,
           which sets these same --tab-glow-* properties on both elements
           at once). The border is an intentional exception to tiles being
           borderless/flush against each other (see the pip-stack comment
           near its markup) — worth breaking that seamlessness for since a
           declared/cancelled fight still playing in the background is
           otherwise indistinguishable from a live one.

           Deliberately its own keyframes rather than reusing
           fight-tab-glow's box-shadow pulse: #pip-stack (the tile's own
           grandparent) clips overflow so it can stack tiles flush against
           each other with zero gap, which also clips almost all of an
           outset box-shadow's blur/spread before it's visible — and an
           inset one would just paint underneath the tile's own opaque
           video iframe. Flashing the border itself (already proven
           visible — it sits right at the tile's own edge, unaffected by
           either problem) between the winner's color and white instead,
           thickened to 3px so it reads clearly even at this tile's small
           size. */
        #pip-tiles [data-pip-tile][data-resolved="1"] {
            border: 3px solid var(--tab-glow-solid, transparent);
            animation: pip-tile-flash 0.4s ease-in-out 7.5;
        }
        @keyframes pip-tile-flash {
            0%, 100% { border-color: var(--tab-glow-solid, transparent); }
            50% { border-color: #fff; }
        }

        /* Static neon glow on the Meron/Wala cards — was an animated
           conic-gradient "comet" spinning around the border, but that
           repaints every frame and was laggy on lower-end/older devices,
           so it's back to a plain static glow for now using the same
           --side-glow-rgb each card already sets inline. */
        .side-neon-card {
            box-shadow: 0 0 16px -4px rgba(var(--side-glow-rgb), 0.6);
        }

        /* A tap on the PiP panel (see setupPip()'s onUp below) briefly
           forces #pip-header visible the same way group-hover does on
           desktop — a touch device has no hover state to trigger that. */
        #pip-stack.pip-header-peek #pip-header {
            opacity: 1;
            pointer-events: auto;
        }

        /* iOS Safari/Chrome (both WebKit) show a long-press link callout
           (share/open-in-new-tab menu) on the tile's <a> overlay — inherited
           from #pip-stack since -webkit-touch-callout, unlike touch-action,
           does cascade to descendants. Without this, a slow-starting drag
           on iOS gets hijacked by that menu partway through, which fires
           pointercancel instead of pointerup — see the pointercancel
           listener in setupPip() below for why that matters. */
        #pip-stack {
            -webkit-touch-callout: none;
        }
    </style>

    {{-- Event switcher — every live event including this one, as equal-width
         tabs with a small banner thumbnail, so a player can jump straight
         to another without backing out to the lobby first. Hidden when
         this is the only live event, same as the fight switcher below it.
         Each entry (other than the current one) routes through
         play.events.enter, which drops the player on that event's own
         current fight — identical to tapping its tile on the lobby.
         grid-auto-columns: minmax(112px, 1fr) is what actually makes every
         tab equal width: each column stretches to fill the row equally
         (1fr) as long as that keeps it at least 112px — wide enough for
         the "Fight #:number - STATUS" meta line below the title to stay
         readable instead of truncating into ellipsis (raised from an
         original 70px floor, back when there was only the title to fit)
         — and only once enough events no longer fit at that floor does
         the row overflow into a horizontal scroll instead of squeezing
         tabs further. --}}
    @if ($liveEvents->count() > 1)
        <div id="event-switcher" class="grid divide-x divide-slate-950 overflow-x-auto scroll-thin py-1" style="grid-auto-flow: column; grid-auto-columns: minmax(112px, 1fr);">
            @foreach ($liveEvents as $liveEvent)
                @php
                    $isCurrent = $liveEvent->id === $event->id;
                    $eventFight = $liveEvent->currentFight;
                @endphp
                <a href="{{ $isCurrent ? route('play.pool-fight', $fight) : route('play.events.enter', $liveEvent) }}"
                   class="event-tab min-w-0 flex items-center gap-1.5 px-1.5 py-1.5 border-b-4 transition first:rounded-l-lg last:rounded-r-lg
                       {{ $isCurrent ? 'bg-slate-700 border-white' : 'bg-slate-800 hover:bg-slate-700 border-transparent' }}"
                   data-event-id="{{ $liveEvent->id }}">
                    <span class="w-7 h-7 rounded-md overflow-hidden shrink-0 bg-slate-700 flex items-center justify-center text-sm">
                        @if ($liveEvent->displayBannerUrl())
                            <img src="{{ $liveEvent->displayBannerUrl() }}" alt="" class="w-full h-full object-cover">
                        @endif
                    </span>
                    <span class="min-w-0 flex flex-col leading-tight">
                        <span class="truncate text-[10px] font-bold {{ $isCurrent ? 'text-white' : 'text-slate-300' }}">{{ $liveEvent->name }}</span>
                        {{-- The event's own latest fight number + status,
                             as words instead of a dot indicator — kept live
                             by setTabStatus() below, which replaces this
                             whole line's text on a FightStatusUpdated
                             broadcast for this event. --}}
                        <span class="event-tab-meta truncate text-[9px] font-semibold text-slate-500" @if (! $eventFight) hidden @endif>
                            @if ($eventFight)
                                <span class="event-tab-number">{{ __('Fight #:number', ['number' => $eventFight->fight_number]) }} -</span>
                                <span class="event-tab-status {{ $eventTabStatusClass($eventFight->status) }}">{{ $statusCopyMap[$eventFight->status]['label'] ?? strtoupper($eventFight->status) }}</span>
                            @endif
                        </span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    <!-- Fight switcher — shown only when another fight for this event is
         also still in play (e.g. one closed and awaiting declaration while
         the next is already open for betting), so players can jump to it
         instead of being stuck on whichever one they landed on. Rebuilt
         live (see renderTabs below) whenever a fight starts/closes/gets
         declared anywhere in this event — not just re-rendered on load. -->
    <div id="fight-tabs" class="flex gap-1.5 overflow-x-auto scroll-thin" @if ($activeFights->count() <= 1) hidden @endif>
        @foreach ($activeFights as $activeFight)
            <a href="{{ route('play.pool-fight', $activeFight) }}"
               data-fight-tab="{{ $activeFight->id }}"
               class="shrink-0 rounded-full px-3 py-1.5 text-xs font-bold uppercase transition
                   {{ $activeFight->id === $fight->id ? 'bg-slate-800 text-white ring-2 ring-inset ring-white/80' : 'bg-slate-800 text-slate-300 hover:bg-slate-700' }}">
                {{ __('Fight #:number', ['number' => $activeFight->fight_number]) }}
            </a>
        @endforeach
    </div>

    <!-- Top bar -->
    <div class="flex items-center justify-between bg-[#160e0a] rounded-t-xl px-4 py-3 border border-[#2a1a14]">
        <div class="flex items-center gap-2">
            <span class="text-emerald-400 font-bold text-sm">{{ __('Fight #') }} <span id="fight-number">{{ $fight->fight_number }}</span></span>
            <span id="fight-status-badge" class="text-[11px] px-2.5 py-0.5 rounded-full font-bold uppercase {{ $statusBadgeClass($fight->status) }}">
                {{ $statusCopy['label'] }}
            </span>
        </div>
        <span class="text-amber-400 font-extrabold text-lg" id="wallet-balance" style="text-shadow: 0 0 16px rgba(251,191,36,0.25);">{{ $theme['currency'] }}<span data-wallet-balance="main">{{ number_format($wallet->main_balance ?? 0, 2) }}</span></span>
    </div>

    <!-- Bet slip -->
    <div id="bet-panel" class="bg-[#160e0a] border border-[#2a1a14] rounded-xl p-4">
        <div class="grid grid-cols-7 gap-1 mb-3">
            @foreach ([[10, '10'], [20, '20'], [50, '50'], [100, '100'], [1000, '1k'], [5000, '5k'], [10000, '10k']] as [$amount, $label])
                <button type="button" class="chip-btn rounded-full bg-[#160e0a] border border-[#3a241b] hover:border-red-500/60 text-[#c9baaf] text-xs sm:text-sm font-extrabold py-2 text-center transition" data-amount="{{ $amount }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="relative">
            <input type="text" inputmode="numeric" id="bet-amount" data-decimals="0" placeholder="{{ __('Amount') }}"
                   class="amount-input w-full rounded-lg bg-[#0b0705] border border-[#2a1a14] pl-3 pr-9 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
            <button type="button" id="clear-bet-amount" class="absolute inset-y-0 right-0 px-3 text-[#8a7a70] hover:text-[#c9baaf] transition" aria-label="{{ __('Clear amount') }}">
                &times;
            </button>
        </div>
    </div>

    {{-- Meron / Wala / Draw — grouped into one wrapper so the Draw bar sits
         flush under the Meron/Wala row (no gap between them). Meron/Wala
         are separate rounded cards with a static rooster image as their
         background (same two images regardless of fight — there's no
         per-fight photo in the schema), keeping the theme's side colors
         for the border glow, badge and bet button. --}}
    <div>
    <!-- Meron / Wala -->
    <div class="grid grid-cols-2 gap-2.5">
        <div class="relative rounded-2xl overflow-hidden flex flex-col side-neon-card" style="--side-glow-rgb:{{ $meronRgb }};border:1px solid rgba({{ $meronRgb }},0.35);background:radial-gradient(circle at 50% 30%, rgba({{ $meronRgb }},0.22), #160e0a 70%);">
            <p class="relative text-center pt-2 text-base font-extrabold tracking-wide" style="color:rgb({{ $meronRgb }});text-shadow:0 2px 6px rgba(0,0,0,0.7);">{{ strtoupper($event->label_meron) }}</p>
            <div class="relative flex-1 px-3 pb-3.5 pt-1 flex flex-col items-center justify-end gap-0.5">
                <p class="text-yellow-400 text-xl font-extrabold" id="meron-pool">{{ number_format($meronPool * $event->multiplier, 2) }}</p>
                <p class="font-bold text-sm" style="color:#f2cba8;">{{ __('Payout') }} <span id="meron-payout-pct">{{ number_format($meronPayoutPct, 2) }}</span>%</p>
                <p class="text-xs mb-1">
                    <span class="text-white font-semibold" id="my-meron-bet">{{ number_format($myMeronBet, 0) }}</span>
                    <span class="text-[#c99a7a]">=</span>
                    <span class="text-emerald-400 font-semibold" id="my-meron-payout">{{ number_format($myMeronBet * $payouts['meron'], 0) }}</span>
                </p>
                <button type="button" data-side="meron" class="place-bet-btn w-full rounded-lg {{ $theme['meron']['btn'] }} disabled:opacity-40 disabled:cursor-not-allowed transition font-bold text-sm py-2" style="box-shadow:0 4px 12px -4px rgba({{ $meronRgb }},0.6);" disabled>
                    {{ __('BET :side', ['side' => strtoupper($event->label_meron)]) }}
                </button>
            </div>
        </div>
        <div class="relative rounded-2xl overflow-hidden flex flex-col side-neon-card" style="--side-glow-rgb:{{ $walaRgb }};border:1px solid rgba({{ $walaRgb }},0.35);background:radial-gradient(circle at 50% 30%, rgba({{ $walaRgb }},0.22), #160e0a 70%);">
            <p class="relative text-center pt-2 text-base font-extrabold tracking-wide" style="color:rgb({{ $walaRgb }});text-shadow:0 2px 6px rgba(0,0,0,0.7);">{{ strtoupper($event->label_wala) }}</p>
            <div class="relative flex-1 px-3 pb-3.5 pt-1 flex flex-col items-center justify-end gap-0.5">
                <p class="text-yellow-400 text-xl font-extrabold" id="wala-pool">{{ number_format($walaPool * $event->multiplier, 2) }}</p>
                <p class="font-bold text-sm" style="color:#f2cba8;">{{ __('Payout') }} <span id="wala-payout-pct">{{ number_format($walaPayoutPct, 2) }}</span>%</p>
                <p class="text-xs mb-1">
                    <span class="text-white font-semibold" id="my-wala-bet">{{ number_format($myWalaBet, 0) }}</span>
                    <span class="text-[#c99a7a]">=</span>
                    <span class="text-emerald-400 font-semibold" id="my-wala-payout">{{ number_format($myWalaBet * $payouts['wala'], 0) }}</span>
                </p>
                <button type="button" data-side="wala" class="place-bet-btn w-full rounded-lg {{ $theme['wala']['btn'] }} disabled:opacity-40 disabled:cursor-not-allowed transition font-bold text-sm py-2" style="box-shadow:0 4px 12px -4px rgba({{ $walaRgb }},0.5);" disabled>
                    {{ __('BET :side', ['side' => strtoupper($event->label_wala)]) }}
                </button>
            </div>
        </div>
    </div>

    <!-- Draw -->
    @if ($fight->draw_enabled)
        <div class="rounded-2xl mt-2.5 px-4 py-3" style="background:#160e0a;border:1px solid rgba(15,118,110,0.4);">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <p class="font-extrabold text-sm" style="color:#5eead4;">{{ __(':side WINS x:multiplier', ['side' => strtoupper($event->label_draw), 'multiplier' => number_format($drawMultiplier, 0)]) }}</p>
                    <p class="text-[#8a7a70] text-xs" id="draw-remaining-text">{{ __(':remaining left of :max pool', ['remaining' => number_format($drawRemaining, 0), 'max' => number_format($maxDrawBet, 0)]) }}</p>
                </div>
                <p class="text-yellow-400 text-2xl font-extrabold" id="draw-pool">{{ number_format($drawPool * $event->multiplier, 2) }}</p>
            </div>
            <button type="button" data-side="draw" class="place-bet-btn w-full rounded-lg bg-teal-700 hover:bg-teal-600 disabled:opacity-40 disabled:cursor-not-allowed transition font-bold text-sm py-2" disabled>
                {{ __('BET :side', ['side' => strtoupper($event->label_draw)]) }}
            </button>
        </div>
    @endif
    </div>

    <!-- Status panel — only worth a caller-out banner while betting isn't
         plainly obvious from the bet slip alone: pending (not open yet),
         last_call (still open, but closing very soon — worth the urgency),
         or closed (no longer open, awaiting declaration). A plain 'open'
         fight has nothing to announce here, and declared/cancelled get
         their own result overlay instead. -->
    <div id="status-panel" class="bg-slate-800 rounded-xl px-4 py-6 text-center"@if (! in_array($fight->status, ['pending', 'last_call', 'closed'])) hidden @endif>
        <p class="text-yellow-400 font-extrabold text-lg" id="fight-status-word">{{ $statusCopy['label'] }}</p>
        <p class="text-slate-400 text-sm mt-1" id="fight-status-desc">{{ $statusCopy['desc'] }}</p>
    </div>

    <!-- Reglahan -->
    <div class="bg-[#160e0a] border border-[#2a1a14] rounded-xl p-3">
        <p class="font-bold text-sm mb-3" style="color:#e0793a;">{{ __('REGLAHAN') }}</p>

        <div class="flex flex-wrap justify-center gap-x-3 gap-y-1.5 mb-3">
            <span class="flex items-center gap-1.5 text-[11px] text-[#c9baaf]">
                <span class="w-5 h-5 rounded-full shrink-0 flex items-center justify-center text-[9px] font-bold text-white" style="background:rgb({{ $meronRgb }});">{{ $stats['meron'] }}</span>
                {{ strtoupper($event->label_meron) }}
            </span>
            <span class="flex items-center gap-1.5 text-[11px] text-[#c9baaf]">
                <span class="w-5 h-5 rounded-full shrink-0 flex items-center justify-center text-[9px] font-bold text-white" style="background:rgb({{ $walaRgb }});">{{ $stats['wala'] }}</span>
                {{ strtoupper($event->label_wala) }}
            </span>
            <span class="flex items-center gap-1.5 text-[11px] text-[#c9baaf]">
                <span class="w-5 h-5 rounded-full shrink-0 flex items-center justify-center text-[9px] font-bold text-[#1e293b]" style="background:#cbd5e1;">{{ $stats['draw'] }}</span>
                {{ strtoupper($event->label_draw) }}
            </span>
            <span class="flex items-center gap-1.5 text-[11px] text-[#c9baaf]">
                <span class="w-5 h-5 rounded-full shrink-0 flex items-center justify-center text-[9px] font-bold text-white" style="background:#52525b;">{{ $stats['cancelled'] }}</span>
                {{ __('Cancelled') }}
            </span>
        </div>

        <div class="overflow-x-auto scroll-thin">
            <div class="grid gap-1" style="grid-auto-flow: column; grid-template-rows: repeat({{ $reglahan['maxRows'] }}, minmax(0, 1fr)); width: max-content;">
                @forelse ($reglahan['columns'] as $column)
                    @foreach ($column as $cell)
                        <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-full border border-[#3a241b] flex items-center justify-center text-xs font-bold
                            {{ $cell
                                ? ($cell->winner === 'meron' ? $theme['meron']['reglahan'] : ($cell->winner === 'wala' ? $theme['wala']['reglahan'] : ($cell->winner === 'draw' ? 'bg-[#cbd5e1] text-[#1e293b]' : 'bg-[#52525b] text-[#e2e8f0]')))
                                : 'bg-[#0b0705] text-[#4a3a30] border-dashed' }}">
                            {{ $cell?->fight_number }}
                        </div>
                    @endforeach
                @empty
                    {{-- No settled fights yet — still show a full placeholder
                         board (matches how the board will look once results
                         start coming in) instead of one lone empty square. --}}
                    @for ($p = 0; $p < 9 * $reglahan['maxRows']; $p++)
                        <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-full border border-dashed border-[#2a1a14] bg-[#0b0705]"></div>
                    @endfor
                @endforelse
            </div>
        </div>
    </div>

    <!-- My bets this fight -->
    <div class="bg-[#160e0a] border border-[#2a1a14] rounded-xl p-4">
        <h2 class="font-semibold text-sm mb-3 text-[#c9baaf]">{{ __('My bets — this fight') }}</h2>
        <div id="my-bets" class="space-y-2 text-sm">
            @forelse ($myBets as $bet)
                <div class="flex justify-between border-b border-[#2a1a14] pb-1">
                    <span>{{ $event->sideLabel($bet->side) }}</span>
                    <span>{{ $theme['currency'] }}{{ number_format($bet->amount, 2) }}</span>
                </div>
            @empty
                <p class="text-[#8a7a70]">{{ __('No bets placed yet.') }}</p>
            @endforelse
        </div>
    </div>

    <!-- Betting history — every bet this player has ever placed, across
         every event, filterable to one event via the dropdown and to
         open/settled via the tabs below, paginated 10 rows at a time. The
         tabs, filter and pagination links (delegated below) all go through
         play.bet-history via AJAX instead of a full page reload, same
         pattern as the superadmin wallets page's live search. -->
    <div class="bg-[#160e0a] border border-[#2a1a14] rounded-xl p-4">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="font-semibold text-sm text-[#c9baaf]">{{ __('Betting history') }}</h2>
            <select id="bet-history-filter" class="rounded-lg bg-[#0b0705] border border-[#2a1a14] px-2 py-1 text-xs">
                <option value="">{{ __('All events') }}</option>
                @foreach ($myBetHistoryEvents as $historyEvent)
                    <option value="{{ $historyEvent->id }}">{{ $historyEvent->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-1.5 bg-[#0b0705] border border-[#2a1a14] rounded-[11px] p-1 mb-3">
            <button type="button" id="bet-history-tab-open" class="bet-history-tab flex-1 rounded-lg py-2 text-xs font-bold text-center transition bg-red-600 text-white shadow-[0_4px_14px_-4px_rgba(220,38,38,0.6)]" data-status="open">{{ __('Open bets') }}</button>
            <button type="button" id="bet-history-tab-settled" class="bet-history-tab flex-1 rounded-lg py-2 text-xs font-bold text-center transition text-[#8a7a70]" data-status="settled">{{ __('Settled bets') }}</button>
        </div>
        <div id="bet-history-results">
            @include('player.partials.bet-history-rows')
        </div>
    </div>
</div>

<script>
    (function () {
        const filter = document.getElementById('bet-history-filter');
        const results = document.getElementById('bet-history-results');
        const tabs = document.querySelectorAll('.bet-history-tab');
        const baseUrl = @json(route('play.bet-history'));
        if (!filter || !results) return;

        let status = 'open';

        function setActiveTab() {
            tabs.forEach((tab) => {
                const active = tab.dataset.status === status;
                tab.classList.toggle('bg-red-600', active);
                tab.classList.toggle('text-white', active);
                tab.classList.toggle('shadow-[0_4px_14px_-4px_rgba(220,38,38,0.6)]', active);
                tab.classList.toggle('text-[#8a7a70]', !active);
            });
        }

        function load(eventId, page) {
            const url = new URL(baseUrl, window.location.origin);
            if (eventId) url.searchParams.set('event_id', eventId);
            url.searchParams.set('status', status);
            if (page && page > 1) url.searchParams.set('page', page);

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } })
                .then((r) => r.text())
                .then((html) => { results.innerHTML = html; })
                .catch(() => {});
        }

        filter.addEventListener('change', () => load(filter.value, 1));

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                if (tab.dataset.status === status) return;
                status = tab.dataset.status;
                setActiveTab();
                load(filter.value, 1);
            });
        });

        // Pagination links render as plain <a href> inside the results
        // container (Laravel's default pagination view) — intercept clicks
        // on them so paging stays in place too, rather than only the filter.
        results.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (!link) return;

            e.preventDefault();
            const page = new URL(link.href, window.location.origin).searchParams.get('page') || 1;
            load(filter.value, page);
        });
    })();
</script>

<!-- Fight result overlay -->
<div id="result-overlay" class="hidden fixed inset-0 bg-black/80 z-50 flex items-center justify-center">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl p-8 text-center max-w-sm">
        <p class="text-sm text-slate-400 mb-2">{{ __('Fight #:number result', ['number' => $fight->fight_number]) }}</p>
        <p id="result-winner" class="text-3xl font-bold mb-6"></p>
        <a href="{{ route('play.events.enter', $event) }}" class="inline-block rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-6 py-2">{{ __('Next fight →') }}</a>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const fightId = {{ $fight->id }};
    const fightNumber = {{ $fight->fight_number }};
    const eventId = {{ $event->id }};
    const statusUrl = @json(route('play.pool-fight.status', $fight));
    const betUrl = @json(route('play.pool-bet', $fight));
    const nextUrl = @json(route('play.events.enter', $event));
    // {{ $fight->id }} is a placeholder swapped for each tab's real fight id below.
    const fightTabsUrlTemplate = @json(route('play.pool-fight', $fight));
    const multiplier = {{ (float) $event->multiplier }};
    const maxDrawBet = {{ (float) $maxDrawBet }};
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const i18n = @json($i18n);
    const statusCopy = i18n.statusCopy;
    const liveEventIds = @json($liveEvents->pluck('id'));

    function eventTabStatusClass(status) {
        if (status === 'open') return 'text-emerald-400';
        if (status === 'last_call') return 'text-yellow-400';
        if (status === 'closed') return 'text-red-400';
        return 'text-slate-400';
    }

    // Updates an event-switcher tab's "Fight #:number - STATUS" line (see
    // its markup above) to whichever fight is now that event's latest —
    // forFightNumber is only omitted when the caller already knows the
    // fight itself hasn't changed, just its status (applyStatus below,
    // called for this page's own fight).
    function setTabStatus(forEventId, status, forFightNumber) {
        const tab = document.querySelector('#event-switcher a[data-event-id="' + forEventId + '"]');
        if (!tab) return;

        const meta = tab.querySelector('.event-tab-meta');
        const numberEl = tab.querySelector('.event-tab-number');
        const statusEl = tab.querySelector('.event-tab-status');
        if (!meta || !numberEl || !statusEl) return;

        if (forFightNumber !== undefined) {
            numberEl.textContent = i18n.fightNumberLabel.replace(':number', forFightNumber) + ' -';
        }
        meta.hidden = false;
        statusEl.textContent = (statusCopy[status] || [status.toUpperCase()])[0];
        statusEl.className = 'event-tab-status ' + eventTabStatusClass(status);
    }

    function hexToRgba(hex, alpha) {
        const n = parseInt(hex.replace('#', ''), 16);
        return 'rgba(' + [(n >> 16) & 255, (n >> 8) & 255, n & 255].join(',') + ',' + alpha + ')';
    }

    // A declared/cancelled fight's tab stays in the fight switcher for a
    // brief winner-colored flash rather than disappearing the instant it's
    // no longer "active" server-side (see renderTabs, which skips removing
    // any tab already marked data-resolved) — and if that fight also
    // happens to be showing as a PiP tile right now, that tile gets the
    // same flash (see applyPip, which likewise skips removing a resolved
    // tile) so the background video announces its own result too. Once the
    // flash ends both are removed outright, here — there's nothing left
    // to protect them from renderTabs/applyPip pruning on the very next
    // poll anyway, since the backend already dropped this fight from both
    // lists the moment it resolved. sideMeta (defined below) is available
    // by the time this actually runs since it's only ever called from an
    // event listener, never synchronously during initial script execution.
    // Kept in sync with the CSS `fight-tab-glow` animation's own total run
    // time (0.4s × 7.5 iterations) on both #fight-tabs and #pip-tiles above.
    const RESOLVED_FLASH_MS = 3000;

    function markFightTabResolved(forFightId, status, winner) {
        const color = status === 'declared' && sideMeta[winner] ? sideMeta[winner].color : '#64748b';
        const solid = color;
        const soft = hexToRgba(color, .8);

        const tab = document.querySelector('#fight-tabs [data-fight-tab="' + forFightId + '"]');
        if (tab) {
            tab.style.setProperty('--tab-glow-solid', solid);
            tab.style.setProperty('--tab-glow-soft', soft);
            tab.dataset.resolved = '1';
        }

        const pipTile = document.querySelector('#pip-tiles [data-pip-tile="' + forFightId + '"]');
        if (pipTile) {
            pipTile.style.setProperty('--tab-glow-solid', solid);
            pipTile.style.setProperty('--tab-glow-soft', soft);
            pipTile.dataset.resolved = '1';
        }

        setTimeout(() => {
            tab?.remove();
            const tabsContainer = document.getElementById('fight-tabs');
            if (tabsContainer) tabsContainer.hidden = tabsContainer.querySelectorAll('[data-fight-tab]').length <= 1;

            pipTile?.remove();
            const stack = document.getElementById('pip-stack');
            const tilesEl = document.getElementById('pip-tiles');
            if (stack && tilesEl) stack.hidden = tilesEl.querySelectorAll('[data-pip-tile]').length === 0;
        }, RESOLVED_FLASH_MS);
    }

    // Mutated in place as bets are placed / payout ratios shift, instead of
    // reloading the page after every action.
    let myMeronBet = {{ (float) $myMeronBet }};
    let myWalaBet = {{ (float) $myWalaBet }};
    let meronPayoutRatio = {{ (float) $payouts['meron'] }};
    let walaPayoutRatio = {{ (float) $payouts['wala'] }};
    // Kept in sync with Fight::BETTABLE_STATUSES on the backend.
    const BETTABLE_STATUSES = ['open', 'last_call'];
    let fightOpen = BETTABLE_STATUSES.includes(@json($fight->status));
    let currentFightStatus = @json($fight->status);

    function statusBadgeClass(status) {
        if (status === 'open') return 'bg-emerald-600 text-white';
        if (status === 'last_call') return 'bg-yellow-500 text-slate-900';
        if (status === 'closed') return 'bg-amber-600 text-white';
        return 'bg-slate-700 text-slate-200';
    }
    const announcedNewFights = new Set();

    // The PiP panel — user-dismissible, but a dismissal only sticks for the
    // exact set of fights it was shown for; if that set changes later (one
    // gets declared, a new one opens elsewhere) it's worth surfacing again.
    (function setupPip() {
        const stack = document.getElementById('pip-stack');
        const tilesEl = document.getElementById('pip-tiles');
        const closeBtn = document.getElementById('pip-close');
        if (!stack || !tilesEl) return;

        let currentPipIds = new Set();
        // Fight IDs the player has explicitly closed (either this one
        // tile's own × or the whole panel's) — kept out of the PiP for
        // the rest of this page view even if a later poll/broadcast would
        // otherwise bring it back (e.g. its status changes). Session-only,
        // not persisted; a fresh page load starts clean.
        let dismissedFightIds = new Set();
        let moved = false;
        let peekTimer = null;

        // The whole panel's header × dismisses every tile currently
        // showing, not just the panel's visibility — otherwise the very
        // next applyPip() call (poll/broadcast) would just bring it all
        // back.
        closeBtn.addEventListener('click', () => {
            currentPipIds.forEach((id) => dismissedFightIds.add(id));
            tilesEl.innerHTML = '';
            currentPipIds = new Set();
            stack.hidden = true;
        });

        function closeTile(fightId) {
            dismissedFightIds.add(fightId);
            const tile = tilesEl.querySelector('[data-pip-tile="' + fightId + '"]');
            if (tile) tile.remove();
            currentPipIds.delete(fightId);
            if (currentPipIds.size === 0) stack.hidden = true;
        }

        window.applyPip = function (pipList) {
            pipList = (pipList || []).filter((p) => !dismissedFightIds.has(p.fight_id));
            const incomingIds = new Set(pipList.map(p => p.fight_id));

            // Drop tiles for fights that are no longer live — except one
            // just marked resolved by markFightTabResolved above, which
            // (same as a resolved fight-switcher tab) stays put with its
            // glow rather than vanishing the instant the backend's own
            // pipFights() query drops a declared/cancelled fight.
            tilesEl.querySelectorAll('[data-pip-tile]').forEach((tile) => {
                if (!incomingIds.has(Number(tile.dataset.pipTile)) && tile.dataset.resolved !== '1') tile.remove();
            });

            // Add or refresh a tile per fight — only touch an iframe's src
            // when it actually changes, so an unrelated poll/broadcast
            // doesn't reload every other fight's video along with it.
            pipList.forEach((pip) => {
                let tile = tilesEl.querySelector('[data-pip-tile="' + pip.fight_id + '"]');

                if (!tile) {
                    tile = document.createElement('div');
                    tile.dataset.pipTile = pip.fight_id;
                    tile.className = 'relative bg-black overflow-hidden';
                    // Set inline rather than relying purely on the
                    // aspect-video utility class — this guarantees the tile
                    // is capped to 16:9 (no tall black letterboxing under a
                    // short/offline placeholder graphic) even if the page's
                    // compiled CSS is stale.
                    tile.style.aspectRatio = '16 / 9';
                    tile.innerHTML =
                        '<iframe class="w-full h-full pointer-events-none" frameborder="0" scrolling="no" allow="autoplay; encrypted-media" referrerpolicy="strict-origin-when-cross-origin"></iframe>' +
                        '<a class="absolute inset-0" title="' + i18n.goToThisFight + '" draggable="false"></a>' +
                        '<span class="absolute bottom-1 left-1 pointer-events-none text-[10px] font-bold px-1.5 py-0.5 rounded bg-black/70 text-white"></span>' +
                        '<button type="button" data-pip-tile-close class="absolute top-1 right-1 w-5 h-5 rounded-full bg-black/70 hover:bg-black/90 text-white text-xs leading-none flex items-center justify-center">&times;</button>';

                    // A drag ending on this tile's link shouldn't also
                    // navigate to it — see the pointerdown handler below,
                    // which sets `moved` for the whole panel's gesture.
                    tile.querySelector('a').addEventListener('click', (e) => {
                        if (moved) e.preventDefault();
                    });

                    // stopPropagation so this doesn't also register as the
                    // start of a panel-drag gesture (see the pointerdown
                    // handler below, which also explicitly ignores it).
                    tile.querySelector('[data-pip-tile-close]').addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        closeTile(pip.fight_id);
                    });

                    tilesEl.appendChild(tile);
                }

                const iframe = tile.querySelector('iframe');
                if (iframe.src !== pip.stream_url) iframe.src = pip.stream_url;
                tile.querySelector('a').href = fightUrl(pip.fight_id);
                // event_name is only set for a fight outside the event
                // currently being viewed (e.g. one the player bet on
                // before switching events) — worth naming so the tile
                // doesn't look like it belongs to this event.
                const label = i18n.fightNumberLabel.replace(':number', pip.fight_number);
                tile.querySelector('span').textContent = pip.event_name ? (pip.event_name + ' — ' + label) : label;
            });

            // Recomputed from the actual DOM rather than just `incomingIds`
            // so a retained resolved tile (see above) still counts — both
            // for the panel's own visibility and so closeBtn's dismiss-all
            // below reaches it too.
            currentPipIds = new Set(Array.from(tilesEl.querySelectorAll('[data-pip-tile]'))
                .map((tile) => Number(tile.dataset.pipTile)));
            stack.hidden = currentPipIds.size === 0;
        };

        // Draggable anywhere on the whole screen — the panel is
        // position:fixed, so getBoundingClientRect() is already in
        // viewport coordinates and clamping is against the window itself,
        // not some containing element. setPointerCapture alone (an
        // earlier version of this) fixed it on desktop but NOT on mobile:
        // a touch's hit-testing against a cross-origin <iframe> (the live
        // stream, covering most of the screen) is partly resolved at the
        // OS/compositor level on phones, which capture doesn't reliably
        // override — the drag would still die the moment a finger crossed
        // it. The actual fix is a transparent, same-document overlay
        // (`dragCatcher` below) dropped on top of literally everything
        // for the duration of the drag: since it's a normal sibling
        // element with a higher stacking context, the browser's hit-test
        // always lands on IT first, and an iframe is never even in the
        // running. A real drag (past a small threshold, so a plain tap
        // still works) suppresses whichever tile link was under the
        // pointer so letting go mid-drag doesn't accidentally navigate.
        // Chat-heads-smooth dragging: pointermove only ever records the
        // latest delta and asks for a frame — the actual style write
        // happens once per rAF tick (never more than once per paint, never
        // blocked behind a slow synchronous handler) — and the live drag
        // moves the panel with `transform: translate3d(...)` rather than
        // `left`/`top`, since a transform is composited on its own layer
        // (no layout/paint per frame) where left/top forces both on every
        // single pointermove. `left`/`top` are only touched once, on
        // release, to commit the final position — see onUp below.
        let startX = 0, startY = 0, startLeft = 0, startTop = 0;
        let pendingDx = 0, pendingDy = 0, rafId = null;
        let dragCatcher = null;
        // Which tile's link (if any) the finger actually went down on —
        // resolved at pointerdown, before dragCatcher exists and starts
        // covering the panel for the gesture's duration. Native click
        // synthesis on that <a> is unreliable here for the same reason
        // dragging across the live-stream iframe was: the preventDefault()
        // below and dragCatcher sitting on top of everything during the
        // gesture can both suppress the browser's own click event on
        // touch, so a plain tap (see onUp) navigates explicitly instead of
        // trusting the anchor's native activation — kept as a fallback
        // for keyboard/desktop rather than removed outright.
        let tappedLink = null;

        // Belt-and-braces against the page itself scrolling under a drag:
        // CSS touch-action:none on #pip-stack (below) is supposed to be
        // enough on its own, but the raw touchmove a mobile browser uses
        // to decide "is this a page pan" keeps targeting whatever element
        // the touch actually STARTED on — not wherever pointer events get
        // redirected to afterward (dragCatcher, via capture) — so this
        // listens on `stack` itself (the real touchstart target) and
        // calls preventDefault with {passive:false}, the one thing
        // guaranteed to stop a native scroll regardless of how touch-
        // action ends up being computed on a given browser/WebView.
        function preventTouchScroll(te) {
            te.preventDefault();
        }

        function applyFrame() {
            rafId = null;
            const maxLeft = Math.max(0, window.innerWidth - stack.offsetWidth);
            const maxTop = Math.max(0, window.innerHeight - stack.offsetHeight);
            const clampedLeft = Math.min(Math.max(0, startLeft + pendingDx), maxLeft);
            const clampedTop = Math.min(Math.max(0, startTop + pendingDy), maxTop);
            stack.style.transform = 'translate3d(' + (clampedLeft - startLeft) + 'px,' + (clampedTop - startTop) + 'px,0)';
        }

        function onMove(e) {
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            if (!moved && (Math.abs(dx) > 4 || Math.abs(dy) > 4)) {
                moved = true;
                stack.dataset.userMoved = '1'; // stop syncTop() from repositioning it on resize
                stack.style.willChange = 'transform';
            }
            if (!moved) return;

            pendingDx = dx;
            pendingDy = dy;
            if (rafId === null) rafId = requestAnimationFrame(applyFrame);
        }

        function onUp(e) {
            stack.removeEventListener('touchmove', preventTouchScroll);

            if (dragCatcher) {
                dragCatcher.removeEventListener('pointermove', onMove);
                dragCatcher.removeEventListener('pointerup', onUp);
                dragCatcher.removeEventListener('pointercancel', onUp);
                dragCatcher.remove();
                dragCatcher = null;
            }

            if (rafId !== null) {
                cancelAnimationFrame(rafId);
                rafId = null;
            }

            if (moved) {
                // Commit the transform's final offset into left/top (the
                // panel's real resting position) and drop the transform —
                // syncTop() and future drags both key off left/top, not
                // whatever transform a previous drag left behind.
                const maxLeft = Math.max(0, window.innerWidth - stack.offsetWidth);
                const maxTop = Math.max(0, window.innerHeight - stack.offsetHeight);
                stack.style.left = Math.min(Math.max(0, startLeft + pendingDx), maxLeft) + 'px';
                stack.style.top = Math.min(Math.max(0, startTop + pendingDy), maxTop) + 'px';
                stack.style.right = 'auto';
                stack.style.transform = '';
                stack.style.willChange = '';
            }

            // A plain tap (not a drag) on the panel briefly reveals its
            // header — the CSS group-hover above only fires on an actual
            // mouse hover, which a touch device never gets. Skipped for a
            // cancelled gesture (e.g. iOS interrupting with its own UI) —
            // that's not a tap, and peeking here would fire with no
            // matching click to hide it again.
            const cancelled = e && e.type === 'pointercancel';
            if (!moved && !cancelled) {
                stack.classList.add('pip-header-peek');
                clearTimeout(peekTimer);
                peekTimer = setTimeout(() => stack.classList.remove('pip-header-peek'), 1800);
            }

            // A plain tap that landed on a tile's link navigates there —
            // see the tappedLink comment above for why this doesn't just
            // rely on the anchor's own native click.
            if (!moved && !cancelled && tappedLink) {
                window.location.href = tappedLink.href;
            }
            tappedLink = null;

            // Deferred so a tile link's own click (which fires right after
            // pointerup) still sees this gesture was a drag.
            setTimeout(() => { moved = false; }, 0);
        }

        stack.addEventListener('pointerdown', (e) => {
            if (e.target === closeBtn || e.target.closest('[data-pip-tile-close]')) return;

            // A tile's link overlay is natively draggable (browsers
            // drag-start links/images by default); left unchecked that
            // hijacks the gesture into a native drag and swallows our own
            // pointermove events after the first one.
            e.preventDefault();

            tappedLink = e.target.closest('#pip-tiles a');
            moved = false;
            pendingDx = 0;
            pendingDy = 0;
            startX = e.clientX;
            startY = e.clientY;

            const rect = stack.getBoundingClientRect();
            startLeft = rect.left;
            startTop = rect.top;

            // See preventTouchScroll above — registered on `stack` itself
            // (the real touchstart target) with {passive:false} so the
            // browser can't start its own page-pan out from under the drag.
            stack.addEventListener('touchmove', preventTouchScroll, { passive: false });

            // A fresh, transparent, full-viewport element created for
            // THIS gesture and torn down in onUp — same document, sits
            // above everything (including the live-stream iframe) for as
            // long as the drag lasts, so every pointermove/up for it is
            // guaranteed to land here rather than being swallowed by
            // whatever's visually underneath the finger.
            dragCatcher = document.createElement('div');
            dragCatcher.style.cssText = 'position:fixed;inset:0;z-index:2147483647;touch-action:none;';
            document.body.appendChild(dragCatcher);
            if (dragCatcher.setPointerCapture) {
                try { dragCatcher.setPointerCapture(e.pointerId); } catch (err) {}
            }

            dragCatcher.addEventListener('pointermove', onMove);
            dragCatcher.addEventListener('pointerup', onUp);
            // iOS can interrupt an in-progress touch gesture with its own
            // UI (e.g. the long-press callout the CSS above now suppresses,
            // or an edge-swipe) and deliver pointercancel instead of
            // pointerup. Without this, those listeners above never get
            // removed — they pile up across every interrupted drag in the
            // session, and each new drag then fires the stale handlers
            // alongside the current one, which is what actually made
            // dragging feel broken on iOS.
            dragCatcher.addEventListener('pointercancel', onUp);
        });

        applyPip(@json($pipFights));
    })();

    document.querySelectorAll('.chip-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const amountEl = document.getElementById('bet-amount');
            amountEl.value = btn.dataset.amount;
            window.applyAccountingFormat(amountEl);
            document.querySelectorAll('.chip-btn').forEach(b => b.classList.remove('chip-btn-active'));
            btn.classList.add('chip-btn-active');
            updatePlaceBtns();
        });
    });

    // A manually-typed amount no longer matches whichever chip was last
    // clicked, so its highlight would be stale — clear it.
    document.getElementById('bet-amount').addEventListener('input', () => {
        document.querySelectorAll('.chip-btn').forEach(b => b.classList.remove('chip-btn-active'));
        updatePlaceBtns();
    });

    // The bet buttons render `disabled` in the initial HTML (their
    // enabled state now depends only on `fightOpen`, not on an amount
    // being pre-filled — see the two-flows comment above placeBet) — sync
    // them to the real fightOpen value once on load rather than leaving
    // them stuck disabled until the next status poll/broadcast happens to
    // call updatePlaceBtns() for an unrelated reason.
    updatePlaceBtns();

    document.getElementById('clear-bet-amount').addEventListener('click', () => {
        document.getElementById('bet-amount').value = '';
        document.querySelectorAll('.chip-btn').forEach(b => b.classList.remove('chip-btn-active'));
        updatePlaceBtns();
    });

    function currentAmount() {
        return parseFloat(window.parseAmount(document.getElementById('bet-amount').value));
    }

    function updatePlaceBtns() {
        // No longer gated on an amount being set — a bet button is always
        // tappable while the fight is open; whether that tap goes straight
        // to a confirmation or opens the amount pop-up is decided at click
        // time (see the two-flows comment below).
        document.querySelectorAll('.place-bet-btn').forEach(btn => { btn.disabled = !fightOpen; });
    }

    const sideMeta = {
        meron: { label: @json($event->label_meron), color: @json($theme['meron']['hex']) },
        wala: { label: @json($event->label_wala), color: @json($theme['wala']['hex']) },
        draw: { label: @json($event->label_draw), color: '#0d9488' },
    };

    function formatAmount(n) {
        return Number(n).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    const swalDark = { background: '#160e0a', color: '#f5efe9' };

    // Two ways into a bet, both funnelling into placeBet() at the end:
    // 1. Amount already set (chip tapped or typed) → tapping "Bet X" goes
    //    straight to a confirm dialog ("1,000 on MERON?").
    // 2. No amount set yet → tapping "Bet X" opens a pop-up with the same
    //    chip presets + a free-amount field; its own Confirm button IS the
    //    confirmation step, so there's no second dialog after it.
    // Either path ends with a success/fail message from placeBet().
    document.querySelectorAll('.place-bet-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const side = btn.dataset.side;
            const amount = currentAmount();

            if (amount && amount > 0) {
                confirmAndPlaceBet(side, amount);
            } else {
                promptAmountAndPlaceBet(side);
            }
        });
    });

    function confirmAndPlaceBet(side, amount) {
        const meta = sideMeta[side];
        Swal.fire({
            title: i18n.confirmBet,
            html: i18n.confirmBetText.replace(':amount', formatAmount(amount)).replace(':side', meta.label.toUpperCase()),
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: i18n.confirmBet,
            cancelButtonText: i18n.cancel,
            confirmButtonColor: meta.color,
            cancelButtonColor: '#3a241b',
            ...swalDark,
        }).then((result) => {
            if (result.isConfirmed) placeBet(side, amount);
        });
    }

    function promptAmountAndPlaceBet(side) {
        const meta = sideMeta[side];
        const presets = [10, 20, 50, 100, 1000, 5000, 10000];
        const chipLabel = (p) => p >= 1000 ? (p / 1000) + 'k' : String(p);
        const chipsHtml = presets.map((p) =>
            `<button type="button" class="swal-amount-chip" data-amount="${p}" style="border-radius:999px;padding:8px 0;font-size:12px;font-weight:800;background:#0b0705;color:#c9baaf;border:1px solid #3a241b;cursor:pointer;">${chipLabel(p)}</button>`
        ).join('');

        Swal.fire({
            title: i18n.enterAmount,
            html:
                `<p style="font-size:13px;color:#8a7a70;margin:0 0 12px;">${i18n.chooseAmountText.replace(':side', meta.label.toUpperCase())}</p>` +
                `<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:10px;">${chipsHtml}</div>` +
                `<input id="swal-amount-input" class="amount-input" type="text" inputmode="numeric" data-decimals="0" placeholder="${i18n.amountPlaceholder}" style="width:100%;box-sizing:border-box;background:#0b0705;border:1px solid #2a1a14;border-radius:9px;padding:9px 12px;color:#f5efe9;font-size:14px;">`,
            showCancelButton: true,
            confirmButtonText: i18n.confirmBet,
            cancelButtonText: i18n.cancel,
            confirmButtonColor: meta.color,
            cancelButtonColor: '#3a241b',
            ...swalDark,
            didOpen: () => {
                const input = document.getElementById('swal-amount-input');
                document.querySelectorAll('.swal-amount-chip').forEach((chip) => {
                    chip.addEventListener('click', () => {
                        input.value = chip.dataset.amount;
                        window.applyAccountingFormat(input);
                        document.querySelectorAll('.swal-amount-chip').forEach((c) => {
                            c.style.background = '#0b0705';
                            c.style.borderColor = '#3a241b';
                            c.style.color = '#c9baaf';
                        });
                        chip.style.background = meta.color;
                        chip.style.borderColor = meta.color;
                        chip.style.color = '#fff';
                    });
                });
            },
            preConfirm: () => {
                const value = parseFloat(window.parseAmount(document.getElementById('swal-amount-input').value));
                if (!value || value <= 0) {
                    Swal.showValidationMessage(i18n.invalidAmount);
                    return false;
                }
                return value;
            },
        }).then((result) => {
            if (result.isConfirmed) placeBet(side, result.value);
        });
    }

    function placeBet(side, amount) {
        document.querySelectorAll('.place-bet-btn').forEach(b => { b.disabled = true; });

        fetch(betUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ side, amount }),
        })
            .then(r => r.json().then(data => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data.success) {
                    Swal.fire({
                        title: i18n.couldNotPlaceBet,
                        text: data.message || i18n.somethingWrong,
                        icon: 'error',
                        confirmButtonColor: '#dc2626',
                        ...swalDark,
                    });
                    updatePlaceBtns();
                    return;
                }
                addMyBet(side, amount);
                // Deliberately NOT clearing the amount — a player betting
                // the same stake on the next fight (or topping up the same
                // side again) shouldn't have to re-type or re-tap a chip
                // every time. updatePlaceBtns() re-enables the buttons
                // immediately since the amount is still valid.
                updatePlaceBtns();
                Swal.fire({
                    title: i18n.betPlaced,
                    html:
                        `<p style="margin:0 0 4px;">${i18n.betPlacedSuccess}</p>` +
                        `<p style="margin:0;font-weight:800;color:${sideMeta[side].color};">${i18n.betPlacedDetail.replace(':amount', formatAmount(amount)).replace(':side', sideMeta[side].label.toUpperCase())}</p>`,
                    icon: 'success',
                    confirmButtonColor: sideMeta[side].color,
                    timer: 3000,
                    timerProgressBar: true,
                    ...swalDark,
                });
            })
            .catch(() => {
                Swal.fire({
                    title: i18n.networkError,
                    text: i18n.couldNotReach,
                    icon: 'error',
                    confirmButtonColor: '#dc2626',
                    ...swalDark,
                });
                updatePlaceBtns();
            });
    }

    // Reflects a just-placed bet immediately instead of reloading the page
    // for it — the pool totals/payout% themselves arrive moments later via
    // this fight's own BetPoolUpdated broadcast (or the 5s poll fallback),
    // same as everyone else watching this fight.
    function addMyBet(side, amount) {
        if (side === 'meron') {
            myMeronBet += amount;
            document.getElementById('my-meron-bet').textContent = fmt(myMeronBet);
            document.getElementById('my-meron-payout').textContent = fmt(myMeronBet * meronPayoutRatio);
        } else if (side === 'wala') {
            myWalaBet += amount;
            document.getElementById('my-wala-bet').textContent = fmt(myWalaBet);
            document.getElementById('my-wala-payout').textContent = fmt(myWalaBet * walaPayoutRatio);
        }

        const list = document.getElementById('my-bets');
        if (!list) return;

        list.querySelector('p')?.remove();

        const row = document.createElement('div');
        row.className = 'flex justify-between border-b border-slate-800 pb-1';
        row.innerHTML = `<span class="capitalize"></span><span></span>`;
        row.firstElementChild.textContent = side;
        row.lastElementChild.textContent = @json($theme['currency']) + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        list.appendChild(row);
    }

    function fmt(n) {
        return Math.round(n).toLocaleString();
    }

    // Pool totals and payout percentages carry 2 decimals (unlike fmt()'s
    // whole-number rounding used for my-bet/my-payout/draw-remaining text).
    function fmt2(n) {
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function poll() {
        fetch(statusUrl, { headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(applyStatus)
            .catch(() => {});
    }

    function fightUrl(id) {
        const parts = fightTabsUrlTemplate.split('/');
        parts[parts.length - 1] = id;

        return parts.join('/');
    }

    // Merges the server's current active-fight list into the bar instead
    // of replacing it outright — a tab whose fight just got declared or
    // cancelled (see markFightTabResolved, called separately off the
    // FightStatusUpdated broadcast, not from here) is no longer "active"
    // server-side, but stays put with its glow here rather than being
    // pruned immediately; markFightTabResolved itself removes it once its
    // 2s flash ends. Navigating to it early (that fight's page redirects
    // on to the event's new current fight) or a page reload also drops it,
    // same as always.
    function renderTabs(activeFights) {
        const container = document.getElementById('fight-tabs');
        if (!container || !activeFights) return;

        const activeIds = new Set(activeFights.map((af) => af.id));

        container.querySelectorAll('[data-fight-tab]').forEach((tab) => {
            if (!activeIds.has(Number(tab.dataset.fightTab)) && tab.dataset.resolved !== '1') {
                tab.remove();
            }
        });

        activeFights.forEach((af) => {
            let tab = container.querySelector('[data-fight-tab="' + af.id + '"]');
            if (!tab) {
                tab = document.createElement('a');
                tab.dataset.fightTab = af.id;
                container.appendChild(tab);
            }

            const cls = af.id === fightId
                ? 'bg-slate-800 text-white ring-2 ring-inset ring-white/80'
                : 'bg-slate-800 text-slate-300 hover:bg-slate-700';
            tab.href = fightUrl(af.id);
            tab.className = 'shrink-0 rounded-full px-3 py-1.5 text-xs font-bold uppercase transition ' + cls;
            tab.textContent = i18n.fightNumberLabel.replace(':number', af.fight_number);
        });

        container.hidden = container.querySelectorAll('[data-fight-tab]').length <= 1;
    }

    // Starts at whatever the server rendered the page with — null when
    // this fight had no cockpit yet at page load. Only touches the DOM
    // when the value actually changes, so a normal poll tick (same
    // stream, or still none) never reloads/flickers an already-playing
    // video.
    let currentMainStreamUrl = @json($mainStreamUrl);

    function applyMainStream(url) {
        if (url === currentMainStreamUrl) return;
        currentMainStreamUrl = url;

        const box = document.getElementById('live-stream');
        const iframe = document.getElementById('live-stream-iframe');
        if (!box || !iframe) return;

        box.hidden = !url;
        iframe.src = url || '';
    }

    function applyStatus(data) {
        renderTabs(data.active_fights);
        // window.applyPip is only ever assigned when #pip-stack exists in
        // the DOM (see setupPip()'s own early return) — absent entirely
        // when the game's video_enabled setting is off.
        if ('pip' in data && typeof window.applyPip === 'function') applyPip(data.pip);
        if ('main_stream_url' in data) applyMainStream(data.main_stream_url);

        document.getElementById('meron-pool').textContent = fmt2(data.meron_pool * multiplier);
        document.getElementById('wala-pool').textContent = fmt2(data.wala_pool * multiplier);
        const drawPoolEl = document.getElementById('draw-pool');
        if (drawPoolEl) drawPoolEl.textContent = fmt2(data.draw_pool * multiplier);
        const drawRemainingEl = document.getElementById('draw-remaining-text');
        if (drawRemainingEl) drawRemainingEl.textContent = i18n.leftOfPool
            .replace(':remaining', fmt(Math.max(0, maxDrawBet - data.draw_pool)))
            .replace(':max', fmt(maxDrawBet));

        // Prefer the server's pre-rounded percents (which, with the
        // balancer on, are derived to sum exactly to its promised total —
        // see PoolPayoutCalculator) over rounding each side independently
        // here, which can drift the displayed sum off by a point.
        document.getElementById('meron-payout-pct').textContent = fmt2(data.meron_payout_pct ?? (data.meron_payout * 100));
        document.getElementById('wala-payout-pct').textContent = fmt2(data.wala_payout_pct ?? (data.wala_payout * 100));

        // "My stake = my potential payout" — the stake is fixed once placed,
        // but the payout side updates live as the pool (and therefore the
        // payout ratio) shifts with everyone else's bets. Ratios are kept
        // around so a freshly-placed bet (addMyBet, below) can price itself
        // without waiting on the next poll/broadcast.
        meronPayoutRatio = data.meron_payout;
        walaPayoutRatio = data.wala_payout;
        document.getElementById('my-meron-payout').textContent = fmt(myMeronBet * meronPayoutRatio);
        document.getElementById('my-wala-payout').textContent = fmt(myWalaBet * walaPayoutRatio);

        fightOpen = BETTABLE_STATUSES.includes(data.status);
        currentFightStatus = data.status;
        setTabStatus(eventId, data.status, fightNumber);
        const badge = document.getElementById('fight-status-badge');
        badge.textContent = (statusCopy[data.status] || [data.status.toUpperCase()])[0];
        badge.className = 'text-[11px] px-2.5 py-0.5 rounded-full font-bold uppercase ' + statusBadgeClass(data.status);

        const [word, desc] = statusCopy[data.status] || [data.status.toUpperCase(), ''];
        document.getElementById('fight-status-word').textContent = word;
        document.getElementById('fight-status-desc').textContent = desc;
        document.getElementById('status-panel').hidden = !['pending', 'last_call', 'closed'].includes(data.status);

        updatePlaceBtns();

        if (data.status === 'declared' || data.status === 'cancelled') {
            showResult(data.status === 'declared' ? data.winner : 'cancelled');
        }
    }

    function showResult(winner) {
        const overlay = document.getElementById('result-overlay');
        // winner is the raw side value (meron/wala/draw) — always in English
        // regardless of region — so it's mapped through sideMeta for the
        // event's actual label (Rojo/Verde/Empate for a Mexico-region event).
        const sideLabel = (sideMeta[winner]?.label || winner).toUpperCase();
        const label = winner === 'cancelled' ? i18n.cancelledRefunded : i18n.wins.replace(':side', sideLabel);
        document.getElementById('result-winner').textContent = label;
        overlay.classList.remove('hidden');
        setTimeout(() => { window.location.href = nextUrl; }, 4000);
    }

    // Live updates via Reverb — every state change that matters here
    // (a bet landing, this fight's own status, another fight in the event
    // starting/closing/declaring) already broadcasts, so no background
    // polling is needed. onEchoReconnect() covers the one gap that leaves:
    // a dropped connection could miss broadcasts while it's down, so a
    // single resync fetch runs every time the socket (re)connects.
    window.addEventListener('echo:ready', () => {
        window.onEchoReconnect(poll);

        window.Echo.channel('fight.' + fightId)
            .listen('.BetPoolUpdated', (e) => applyStatus({
                // currentFightStatus, not the page's initial Blade-rendered
                // status — that's a load-time snapshot and goes stale the
                // moment a FightStatusUpdated broadcast changes it (e.g. the
                // fight opens for betting after this page loaded); reusing
                // it here would snap the badge back to that stale status on
                // every bet anyone places on this fight.
                status: currentFightStatus,
                winner: null,
                meron_pool: e.meron_pool,
                wala_pool: e.wala_pool,
                draw_pool: e.draw_pool,
                meron_payout: e.meron_payout,
                wala_payout: e.wala_payout,
                meron_payout_pct: e.meron_payout_pct,
                wala_payout_pct: e.wala_payout_pct,
            }))
            .listen('.FightStatusUpdated', (e) => {
                if (e.fight_id !== fightId) return;
                // Set synchronously (poll() itself resolves later, over the
                // network) so a next-fight broadcast that lands moments
                // after this one — same declare/cancel, e.g. via
                // createNextFight() — never reads a stale 'closed' below.
                currentFightStatus = e.status;
                poll();
            });

        // Other fights under this event (a new one starting, another
        // closing or getting declared) aren't this page's own fight — this
        // page has no other way to learn about them, so just re-poll to
        // refresh the fight-switcher tabs above.
        window.Echo.channel('event.' + eventId)
            .listen('.FightStatusUpdated', (e) => {
                if (e.fight_id === fightId) return;

                // A new fight replacing this event's own now-settled one —
                // keep this event's own switcher tab meta current too.
                setTabStatus(eventId, e.status, e.fight_number);

                if (e.status === 'declared' || e.status === 'cancelled') {
                    markFightTabResolved(e.fight_id, e.status, e.winner);
                }

                // Only the declarator's manual "Start next fight" pops this
                // — not the routine next fight auto-created right after
                // declaring/cancelling THIS fight (e.manual_start is false
                // there), since that player is about to see the result
                // overlay instead and doesn't need a second notice.
                if (e.status === 'pending' && e.manual_start && currentFightStatus === 'closed' && !announcedNewFights.has(e.fight_id)) {
                    announcedNewFights.add(e.fight_id);

                    Swal.fire({
                        title: i18n.newFightTitle,
                        html: i18n.newFightHtml.replace(':number', e.fight_number),
                        icon: 'info',
                        background: '#0f172a',
                        color: '#e2e8f0',
                        showCancelButton: true,
                        confirmButtonText: i18n.proceedToNextFight,
                        cancelButtonText: i18n.cancel,
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#334155',
                        reverseButtons: true,
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = fightUrl(e.fight_id);
                        }
                    });
                }

                poll();
            });

        // Every OTHER live event in the switcher above — its own public
        // channel is the only way to learn its latest fight's status
        // changed, since none of this page's other subscriptions cover it.
        // Kept to a plain status update (no popups, no re-poll) since none
        // of the rest of this page's state concerns another event.
        liveEventIds.filter((id) => id !== eventId).forEach((otherEventId) => {
            window.Echo.channel('event.' + otherEventId)
                .listen('.FightStatusUpdated', (e) => setTabStatus(otherEventId, e.status, e.fight_number));
        });
    });
})();
</script>
@endpush
@endsection
