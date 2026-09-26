@extends('layouts.app')

@section('title', __('Write a Bet Ticket'))

@php
    $i18n = [
        'couldNotLoadTicket' => __('Could not load that ticket to print.'),
        'couldNotVoid' => __('Could not void this ticket.'),
        'networkErrorVoid' => __('Network error — could not void this ticket.'),
        'couldNotWrite' => __('Could not write the ticket.'),
        'networkErrorWrite' => __('Network error — could not write the ticket.'),
    ];
@endphp

@section('content')
<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">{{ __('Write a Bet Ticket') }}</h1>
        <a href="{{ route('teller.dashboard') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Back to dashboard') }}</a>
    </div>

    <div class="grid gap-6 lg:grid-cols-[26rem_1fr] items-start">
        <!-- Left: betting controls -->
        <div>
            <p class="text-sm text-slate-400 mb-4">
                {{ __('For a walk-up bettor with no account. Collect their cash, write the ticket, then hand them the printed receipt — that\'s their only proof of the bet.') }}
            </p>

            <div id="ticket-error" class="hidden mb-4 rounded-lg border border-red-700 bg-red-900/40 px-4 py-3 text-red-200 text-sm"></div>
            <div id="ticket-success" class="hidden mb-4 rounded-lg border border-emerald-700 bg-emerald-900/40 px-4 py-3 text-emerald-200 text-sm"></div>

            <div id="bet-panel-container" class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6">
                @include('teller.tickets._bet_panel', [
                    'event' => $event, 'fight' => $fight, 'theme' => $theme,
                    'meronPool' => $meronPool, 'walaPool' => $walaPool, 'drawPool' => $drawPool,
                    'payouts' => $payouts, 'drawMultiplier' => $drawMultiplier,
                ])
            </div>

            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <h2 class="font-semibold mb-3">{{ __('Redeem a winning ticket') }}</h2>
                <form method="POST" action="{{ route('teller.tickets.lookup') }}" class="flex gap-2">
                    @csrf
                    <input type="text" name="code" required placeholder="{{ __('e.g. 0000000007') }}"
                           class="flex-1 rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 uppercase">
                    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2">{{ __('Go') }}</button>
                </form>
            </div>
        </div>

        <!-- Right: ticket history -->
        @if ($event)
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h2 class="font-semibold whitespace-nowrap">{{ __('Ticket history · :event', ['event' => $event->name]) }}</h2>
                    <input type="search" id="history-search" placeholder="{{ __('Search ticket #') }}"
                           class="w-full max-w-[220px] rounded-lg bg-slate-800 border border-slate-700 px-3 py-1.5 text-sm">
                </div>
                <div class="overflow-x-auto scroll-thin">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-800">
                                <th class="py-1 pr-2">{{ __('Fight') }}</th>
                                <th class="pr-2">{{ __('Side') }}</th>
                                <th class="pr-2">{{ __('Amount') }}</th>
                                <th class="pr-2">{{ __('Status') }}</th>
                                <th class="pr-2">{{ __('Ticket #') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="history-body">
                            @include('teller.tickets._history_rows', ['history' => $history])
                        </tbody>
                    </table>
                    <p id="history-no-match" class="hidden py-4 text-center text-slate-500 text-sm">{{ __('No tickets match that search.') }}</p>
                </div>
            </div>
        @endif
    </div>
</div>

<!-- Receipt pop-up — the ticket prints straight from here, no page
     navigation, so the teller stays put and can write the next bet the
     moment the print dialog closes. Reused for reprints from the history
     table below. -->
<div id="receipt-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm px-4">
    <div class="w-full max-w-xs">
        <div id="receipt-modal-content"></div>
        <button type="button" id="receipt-modal-close" class="mt-3 w-full rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold py-2 text-sm">
            {{ __('Done') }}
        </button>
    </div>
</div>

<!-- Void confirmation — an admin types their PIN here, in person, to
     approve pulling a ticket out of the pool. -->
<div id="void-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm px-4">
    <div class="w-full max-w-xs bg-slate-900 border border-slate-800 rounded-2xl p-5">
        <p class="text-center text-sm text-slate-400 mb-1">{{ __('Void ticket') }} <span id="void-modal-code" class="font-mono text-slate-200"></span></p>
        <p class="text-center text-xs text-slate-500 mb-4">{{ __('Requires admin approval — have an admin enter their PIN.') }}</p>
        <input type="password" inputmode="numeric" id="void-pin-input" maxlength="6" placeholder="{{ __('Admin PIN') }}"
               class="w-full text-center text-2xl tracking-[0.5em] rounded-lg bg-slate-800 border border-slate-700 px-3 py-3 mb-3">
        <p id="void-modal-error" class="hidden text-red-400 text-xs text-center mb-3"></p>
        <div class="grid grid-cols-2 gap-2">
            <button type="button" id="void-modal-cancel" class="rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold py-2 text-sm">{{ __('Cancel') }}</button>
            <button type="button" id="void-modal-confirm" class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold py-2 text-sm">{{ __('Void bet') }}</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const i18n = @json($i18n);
    const errorBanner = document.getElementById('ticket-error');
    const successBanner = document.getElementById('ticket-success');
    const modal = document.getElementById('receipt-modal');
    const modalContent = document.getElementById('receipt-modal-content');
    const modalClose = document.getElementById('receipt-modal-close');
    const statusUrl = @json(route('teller.tickets.status'));
    const historyBody = document.getElementById('history-body');
    const historySearch = document.getElementById('history-search');
    const historyNoMatch = document.getElementById('history-no-match');
    const betPanelContainer = document.getElementById('bet-panel-container');

    // Tracks the fight currently shown so a same-fight poll tick only
    // patches pool numbers in place (an amount the teller is mid-typing
    // must survive it) — only a genuine change (closes, a new one opens,
    // the event ends) swaps the whole panel and re-focuses/resets it.
    let currentOpen = @json((bool) $fight);
    let currentFightId = @json($fight?->id);
    let subscribedEventId = null;
    let subscribedFightId = null;

    function fmt(n) {
        return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function showError(message) {
        successBanner.classList.add('hidden');
        errorBanner.textContent = message;
        errorBanner.classList.remove('hidden');
    }

    function showSuccess(message) {
        errorBanner.classList.add('hidden');
        successBanner.textContent = message;
        successBanner.classList.remove('hidden');
    }

    function hideBanners() {
        errorBanner.classList.add('hidden');
        successBanner.classList.add('hidden');
    }

    // Filters the already-loaded history rows by ticket number — client
    // side, since the table only ever holds the most recent 100 tickets
    // for this event. Re-applied after every poll refresh (see
    // applyPoolData) since the rows get replaced wholesale each time.
    function applyHistorySearch() {
        if (!historyBody) return;

        const term = historySearch.value.trim().toLowerCase();
        let visibleCount = 0;

        historyBody.querySelectorAll('tr[data-ticket-code]').forEach((row) => {
            const match = !term || row.dataset.ticketCode.toLowerCase().includes(term);
            row.classList.toggle('hidden', !match);
            if (match) visibleCount++;
        });

        historyNoMatch?.classList.toggle('hidden', !term || visibleCount > 0);
    }

    historySearch?.addEventListener('input', applyHistorySearch);

    // Pools — and the ticket history table — shift constantly during arena
    // betting (this teller's own tickets, other tellers, and app/RFID bets
    // all land on the same fight), so both refresh on a timer rather than
    // only on page load or this teller's own actions. A fight open/closed
    // or the current fight itself changing (only present on the status()
    // poll, not on this teller's own store() response) additionally swaps
    // the whole bet panel so the OPEN badge, fight number, and controls
    // never go stale while this page sits open.
    function applyPoolData(data) {
        const identityChanged = 'open' in data
            && (data.open !== currentOpen || (data.open && data.fight_id !== currentFightId));

        if (identityChanged && typeof data.betPanelHtml === 'string') {
            currentOpen = data.open;
            currentFightId = data.open ? data.fight_id : null;
            betPanelContainer.innerHTML = data.betPanelHtml;
            setupBetPanel();
            subscribeFightChannel(currentFightId);
        } else {
            const meronPool = document.getElementById('meron-pool');
            const walaPool = document.getElementById('wala-pool');
            const drawPool = document.getElementById('draw-pool');
            const meronPct = document.getElementById('meron-payout-pct');
            const walaPct = document.getElementById('wala-payout-pct');

            if (meronPool) meronPool.textContent = @json($theme['currency']) + fmt(data.meronPool);
            if (walaPool) walaPool.textContent = @json($theme['currency']) + fmt(data.walaPool);
            if (drawPool) drawPool.textContent = @json($theme['currency']) + fmt(data.drawPool);
            if (meronPct) meronPct.textContent = fmt(data.payouts?.meron_pct ?? ((data.payouts?.meron || 0) * 100));
            if (walaPct) walaPct.textContent = fmt(data.payouts?.wala_pct ?? ((data.payouts?.wala || 0) * 100));
        }

        if (historyBody && typeof data.historyHtml === 'string') {
            historyBody.innerHTML = data.historyHtml;
            applyHistorySearch();
        }

        if (data.event_id) subscribeEventChannel(data.event_id);
    }

    function pollPoolData() {
        fetch(statusUrl, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((data) => applyPoolData(data))
            .catch(() => {});
    }

    // Live updates via Reverb — a fight closing, a new one opening, or the
    // event ending all broadcast on this channel.
    function subscribeEventChannel(eventId) {
        if (!window.Echo || subscribedEventId === eventId) return;
        subscribedEventId = eventId;
        window.Echo.channel('event.' + eventId).listen('.FightStatusUpdated', pollPoolData);
    }

    // Every bet placed on the current fight — by this teller, another
    // teller, or a player's own app — broadcasts here too, so the pool
    // totals stay live without polling. Re-subscribes whenever the current
    // fight changes (a close/open/redeclare swaps which channel matters).
    function subscribeFightChannel(fightId) {
        if (!window.Echo || subscribedFightId === fightId) return;
        subscribedFightId = fightId;
        if (fightId) window.Echo.channel('fight.' + fightId).listen('.BetPoolUpdated', pollPoolData);
    }

    window.addEventListener('echo:ready', () => {
        @if ($event)
            subscribeEventChannel({{ $event->id }});
        @endif
        subscribeFightChannel(currentFightId);

        // No polling — everything above already broadcasts — but a resync
        // still runs on reconnect in case something landed while the socket
        // was down.
        window.onEchoReconnect(pollPoolData);
    });

    pollPoolData();

    // Set by the write-ticket block below (only present when a fight is
    // open) so the single afterprint handler can also reset that form —
    // left null on a reprint-only view (no active fight) or after a
    // reprint from the history table, where there's no form to reset.
    let afterReceiptClosed = null;

    function closeReceiptModal() {
        modal.classList.add('hidden');
        modalContent.innerHTML = '';
    }

    function showReceipt(html, onClosed) {
        afterReceiptClosed = onClosed || null;
        modalContent.innerHTML = html;
        modal.classList.remove('hidden');

        // Auto-print the instant the receipt is on screen — a short delay
        // lets the QR SVG finish painting first.
        setTimeout(() => window.print(), 150);
    }

    // The print dialog closing (confirmed or cancelled) is the cue that the
    // teller is done with this ticket.
    window.addEventListener('afterprint', () => {
        if (modal.classList.contains('hidden')) return;

        closeReceiptModal();
        afterReceiptClosed?.();
        afterReceiptClosed = null;
    });

    modalClose.addEventListener('click', () => {
        closeReceiptModal();
        afterReceiptClosed?.();
        afterReceiptClosed = null;
    });

    function reprint(code) {
        fetch(@json(route('teller.tickets.receipt', ['bet' => '__CODE__'])).replace('__CODE__', code), {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((data) => { if (data.success) showReceipt(data.receipt_html); })
            .catch(() => showError(i18n.couldNotLoadTicket));
    }

    // Void — the PIN modal itself has no route baked in, it just POSTs to
    // whichever ticket code the history row's button carried.
    const voidModal = document.getElementById('void-modal');
    const voidModalCode = document.getElementById('void-modal-code');
    const voidPinInput = document.getElementById('void-pin-input');
    const voidModalError = document.getElementById('void-modal-error');
    const voidModalCancel = document.getElementById('void-modal-cancel');
    const voidModalConfirm = document.getElementById('void-modal-confirm');
    let voidingCode = null;

    function openVoidModal(code) {
        voidingCode = code;
        voidModalCode.textContent = code;
        voidPinInput.value = '';
        voidModalError.classList.add('hidden');
        voidModal.classList.remove('hidden');
        voidPinInput.focus();
    }

    function closeVoidModal() {
        voidModal.classList.add('hidden');
        voidingCode = null;
    }

    voidModalCancel.addEventListener('click', closeVoidModal);

    function confirmVoid() {
        if (!voidingCode || !voidPinInput.value) return;

        voidModalConfirm.disabled = true;

        fetch(@json(route('teller.tickets.void', ['bet' => '__CODE__'])).replace('__CODE__', voidingCode), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
            body: new URLSearchParams({ pin: voidPinInput.value }),
        })
            .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                voidModalConfirm.disabled = false;

                if (!ok || !data.success) {
                    voidModalError.textContent = data.message || i18n.couldNotVoid;
                    voidModalError.classList.remove('hidden');
                    return;
                }

                closeVoidModal();
                hideBanners();
                showSuccess(data.message);
                pollPoolData();
            })
            .catch(() => {
                voidModalConfirm.disabled = false;
                voidModalError.textContent = i18n.networkErrorVoid;
                voidModalError.classList.remove('hidden');
            });
    }

    voidModalConfirm.addEventListener('click', confirmVoid);
    voidPinInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') confirmVoid(); });

    // History rows are replaced wholesale on every poll, so their buttons
    // are handled via delegation on the (stable) table body instead of
    // binding listeners to elements that get thrown away.
    if (historyBody) {
        historyBody.addEventListener('click', (e) => {
            const printBtn = e.target.closest('.history-print-btn');
            if (printBtn) { reprint(printBtn.dataset.code); return; }

            const voidBtn = e.target.closest('.history-void-btn');
            if (voidBtn) openVoidModal(voidBtn.dataset.code);
        });
    }

    // Side is chosen by tapping a MERON/WALA/DRAW panel (mirrors the player
    // betting UI) rather than a separate dropdown. The bet is written via
    // fetch instead of a normal form submit so the receipt can pop up right
    // here — no page navigation, so the teller never leaves this screen.
    // Re-run after every bet-panel swap (see applyPoolData) since that
    // replaces #ticket-form and its buttons with fresh DOM nodes; a no-op
    // when the panel is showing "no active event"/"betting not open" instead.
    function setupBetPanel() {
        const form = document.getElementById('ticket-form');
        if (!form) return;

        const sideInput = document.getElementById('ticket-side-input');
        const amountInput = document.getElementById('ticket-amount-input');
        const sideButtons = document.querySelectorAll('.ticket-side-btn');

        function setBusy(busy) {
            sideButtons.forEach((b) => { b.disabled = busy; });
        }

        function resetForNextTicket() {
            form.reset();
            amountInput.focus();
        }

        sideButtons.forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();

                const rawAmount = window.parseAmount(amountInput.value);
                if (!rawAmount || Number(rawAmount) <= 0) {
                    amountInput.focus();
                    amountInput.reportValidity?.();
                    return;
                }

                hideBanners();
                sideInput.value = btn.dataset.side;
                setBusy(true);

                const formData = new FormData(form);
                formData.set('amount', rawAmount);

                fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: formData,
                })
                    .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
                    .then(({ ok, data }) => {
                        setBusy(false);

                        if (!ok || !data.success) {
                            if (data.redirect) {
                                window.location.href = data.redirect;
                                return;
                            }
                            showError(data.message || i18n.couldNotWrite);
                            return;
                        }

                        applyPoolData(data);
                        showReceipt(data.receipt_html, resetForNextTicket);
                    })
                    .catch(() => {
                        setBusy(false);
                        showError(i18n.networkErrorWrite);
                    });
            });
        });
    }

    setupBetPanel();
})();
</script>
@endpush
@endsection
