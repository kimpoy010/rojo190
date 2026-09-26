@extends('layouts.app')

@section('title', __(':name — RFID Kiosk', ['name' => $terminal->name]))

@php
    $i18n = [
        'ready' => __('Ready: :amount — tap your card on the :meron or :wala reader now (expires in 60s)', [
            'meron' => strtoupper($event?->label_meron ?? $theme['meron']['label']),
            'wala' => strtoupper($event?->label_wala ?? $theme['wala']['label']),
        ]),
        'armFailed' => __('Could not arm amount — check connection and try again.'),
        'fightNumber' => __('Fight #:number'),
        'open' => __('OPEN'),
        'closed' => __('CLOSED'),
    ];
@endphp

@section('content')
<!-- The shared layout's <main> caps at max-w-6xl — a kiosk display should
     use the full screen, so override it for just this page. -->
<style>main { max-width: 100% !important; padding-left: 1rem !important; padding-right: 1rem !important; }</style>
<div class="w-full space-y-4">

    <!-- Result banner -->
    <div id="result-banner" class="hidden rounded-xl px-4 py-3 text-center font-semibold text-lg"></div>

    <!-- Header -->
    <div class="flex items-center justify-between bg-slate-900 rounded-xl px-4 py-3 border border-slate-800">
        <div>
            <p class="font-bold text-lg text-red-400">{{ $terminal->name }}</p>
            <p class="text-xs text-slate-500">{{ $event?->name ?? __('No active event') }}</p>
        </div>
        <div class="text-right">
            <p class="text-emerald-400 font-bold text-sm" id="fight-label">
                {{ $fight ? __('Fight #:number', ['number' => $fight->fight_number]) : '—' }}
            </p>
            <span id="fight-status" class="text-[11px] px-2.5 py-0.5 rounded-full font-bold uppercase {{ $fight ? 'bg-emerald-600 text-white' : 'bg-slate-700 text-slate-300' }}">
                {{ $fight ? __('OPEN') : __('CLOSED') }}
            </span>
        </div>
    </div>

    <!-- Armed-amount status: the only visible cue for bet entry, since
         amount is entered entirely via the numpad modal below. -->
    <p id="bet-armed-status" class="text-center text-sm text-slate-500">{!! __('Press :key on the keypad to enter a bet amount.', ['key' => '<span class="font-mono px-1.5 py-0.5 rounded bg-slate-800">Enter</span>']) !!}</p>

    <!-- Meron / Wala panels -->
    <div class="grid grid-cols-2 gap-1 rounded-xl overflow-hidden">
        <div class="{{ $theme['meron']['panel'] }} p-8 text-center">
            <p class="text-yellow-400 font-extrabold tracking-wide text-[40pt] mb-3">{{ strtoupper($event?->label_meron ?? $theme['meron']['label']) }}</p>
            <p class="text-yellow-400 text-[40pt] font-extrabold mb-3" id="meron-pool">{{ number_format($meronPool, 2) }}</p>
            <p class="text-white font-bold text-[23pt]">{{ __('PAYOUT:') }} <span id="meron-payout-pct">{{ number_format($payouts['meron_pct'], 2) }}</span>%</p>
        </div>
        <div class="{{ $theme['wala']['panel'] }} p-8 text-center">
            <p class="text-yellow-400 font-extrabold tracking-wide text-[40pt] mb-3">{{ strtoupper($event?->label_wala ?? $theme['wala']['label']) }}</p>
            <p class="text-yellow-400 text-[40pt] font-extrabold mb-3" id="wala-pool">{{ number_format($walaPool, 2) }}</p>
            <p class="text-white font-bold text-[23pt]">{{ __('PAYOUT:') }} <span id="wala-payout-pct">{{ number_format($payouts['wala_pct'], 2) }}</span>%</p>
        </div>
    </div>
    <div class="text-center">
        <p class="text-slate-500 text-sm uppercase tracking-wide mb-2">{{ __('Bet Amount') }}</p>
        <p id="confirmed-bet-amount" class="text-[50pt] font-extrabold text-yellow-400">—</p>
    </div>
    <p class="text-center text-sm text-slate-500">{{ __('Tap your card on the :meron or :wala reader to place the bet.', ['meron' => strtoupper($event?->label_meron ?? $theme['meron']['label']), 'wala' => strtoupper($event?->label_wala ?? $theme['wala']['label'])]) }}</p>

</div>

<!-- Numpad bet-amount entry modal — driven by the physical numpad keyboard
     (Enter opens it, digits type, Enter confirms, Esc cancels). This is
     the only way to set a bet amount on this kiosk. -->
<div id="numpad-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm px-4">
    <div class="w-full max-w-xs bg-slate-900 border border-slate-800 rounded-2xl p-5">
        <p class="text-center text-sm text-slate-400 mb-1">{{ __('Enter bet amount') }}</p>
        <p id="numpad-display" class="text-center text-4xl font-extrabold text-yellow-400 mb-4">{{ $theme['currency'] }}0</p>

        <div class="grid grid-cols-3 gap-2 mb-3">
            @foreach ([1,2,3,4,5,6,7,8,9] as $digit)
                <button type="button" class="numpad-digit-btn rounded-lg bg-slate-800 hover:bg-slate-700 text-xl font-bold py-3" data-digit="{{ $digit }}">{{ $digit }}</button>
            @endforeach
            <button type="button" id="numpad-clear" class="rounded-lg bg-slate-800 hover:bg-slate-700 text-sm font-bold py-3">{{ __('CLEAR') }}</button>
            <button type="button" class="numpad-digit-btn rounded-lg bg-slate-800 hover:bg-slate-700 text-xl font-bold py-3" data-digit="0">0</button>
            <button type="button" id="numpad-backspace" class="rounded-lg bg-slate-800 hover:bg-slate-700 text-xl font-bold py-3">⌫</button>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <button type="button" id="numpad-cancel" class="rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold py-2">{{ __('Cancel') }}</button>
            <button type="button" id="numpad-confirm" class="rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold py-2">{{ __('Confirm') }}</button>
        </div>
        <p class="text-center text-xs text-slate-500 mt-3">{{ __('Enter = confirm · Esc = cancel') }}</p>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const token = @json($terminal->token);
    const statusUrl = @json(route('kiosk.status', $terminal));
    const armUrl = @json(route('kiosk.arm', $terminal));
    const csrf = @json(csrf_token());
    const i18n = @json($i18n);
    const eventId = @json($event?->id);
    let subscribedFightId = null;

    const banner = document.getElementById('result-banner');
    let bannerTimeout = null;

    function showBanner(success, message) {
        banner.textContent = message;
        banner.className = 'rounded-xl px-4 py-3 text-center font-semibold text-lg ' +
            (success ? 'bg-emerald-900/60 border border-emerald-700 text-emerald-200' : 'bg-red-900/60 border border-red-700 text-red-200');
        clearTimeout(bannerTimeout);
        bannerTimeout = setTimeout(() => banner.classList.add('hidden'), 8000);
    }

    const betStatusEl = document.getElementById('bet-armed-status');
    const betIdleText = betStatusEl.textContent;
    const confirmedAmountEl = document.getElementById('confirmed-bet-amount');

    function arm(amount) {
        if (!amount || amount <= 0) return;

        fetch(armUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: JSON.stringify({ context: 'bet', amount }),
        })
            .then(r => r.json())
            .then(() => {
                betStatusEl.textContent = i18n.ready.replace(':amount', @json($theme['currency']) + Number(amount).toLocaleString());
                betStatusEl.classList.add('text-emerald-400', 'font-semibold');
                confirmedAmountEl.textContent = @json($theme['currency']) + Number(amount).toLocaleString();
            })
            .catch(() => {
                betStatusEl.textContent = i18n.armFailed;
            });
    }

    function resetBetStatus() {
        betStatusEl.textContent = betIdleText;
        betStatusEl.classList.remove('text-emerald-400', 'font-semibold');
        confirmedAmountEl.textContent = '—';
    }

    // Numpad-driven bet amount entry — the physical keypad is a plain USB
    // keyboard, so a global keydown listener sees its keystrokes directly
    // (no serial bridge involved, unlike the RFID readers).
    const numpadModal = document.getElementById('numpad-modal');
    const numpadDisplay = document.getElementById('numpad-display');
    let numpadOpen = false;
    let numpadBuffer = '';

    function isTypingInField() {
        const el = document.activeElement;
        return el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA');
    }

    function updateNumpadDisplay() {
        numpadDisplay.textContent = @json($theme['currency']) + (numpadBuffer === '' ? '0' : Number(numpadBuffer).toLocaleString());
    }

    function openNumpad() {
        numpadOpen = true;
        numpadBuffer = '';
        updateNumpadDisplay();
        numpadModal.classList.remove('hidden');
    }

    function closeNumpad() {
        numpadOpen = false;
        numpadBuffer = '';
        numpadModal.classList.add('hidden');
    }

    function appendNumpadDigit(digit) {
        if (numpadBuffer.length >= 6) return; // $999,999 cap — plenty for a bet
        numpadBuffer += String(digit);
        updateNumpadDisplay();
    }

    function numpadBackspace() {
        numpadBuffer = numpadBuffer.slice(0, -1);
        updateNumpadDisplay();
    }

    function confirmNumpadAmount() {
        const amount = Number(numpadBuffer);
        if (!amount || amount <= 0) {
            closeNumpad();
            return;
        }

        arm(amount);
        closeNumpad();
    }

    document.querySelectorAll('.numpad-digit-btn').forEach((btn) => {
        btn.addEventListener('click', () => appendNumpadDigit(btn.dataset.digit));
    });
    document.getElementById('numpad-backspace').addEventListener('click', numpadBackspace);
    document.getElementById('numpad-clear').addEventListener('click', () => { numpadBuffer = ''; updateNumpadDisplay(); });
    document.getElementById('numpad-cancel').addEventListener('click', closeNumpad);
    document.getElementById('numpad-confirm').addEventListener('click', confirmNumpadAmount);

    document.addEventListener('keydown', (e) => {
        if (!numpadOpen) {
            if (e.key === 'Enter' && !isTypingInField()) {
                e.preventDefault();
                openNumpad();
            }
            return;
        }

        if (e.key >= '0' && e.key <= '9') {
            e.preventDefault();
            appendNumpadDigit(e.key);
        } else if (e.key === 'Backspace') {
            e.preventDefault();
            numpadBackspace();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            confirmNumpadAmount();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeNumpad();
        }
    });

    function applyStatus(data) {
        const label = document.getElementById('fight-label');
        const badge = document.getElementById('fight-status');

        if (!data.open) {
            label.textContent = '—';
            badge.textContent = i18n.closed;
            badge.className = 'text-[11px] px-2.5 py-0.5 rounded-full font-bold uppercase bg-slate-700 text-slate-300';
            return;
        }

        label.textContent = i18n.fightNumber.replace(':number', data.fight_number);
        badge.textContent = i18n.open;
        badge.className = 'text-[11px] px-2.5 py-0.5 rounded-full font-bold uppercase bg-emerald-600 text-white';
        const fmt2 = (n) => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        document.getElementById('meron-pool').textContent = fmt2(data.meronPool);
        document.getElementById('wala-pool').textContent = fmt2(data.walaPool);
        document.getElementById('meron-payout-pct').textContent = fmt2(data.payouts.meron_pct);
        document.getElementById('wala-payout-pct').textContent = fmt2(data.payouts.wala_pct);
    }

    function poll() {
        fetch(statusUrl, { headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(data => {
                applyStatus(data);
                subscribeFightChannel(data.open ? data.fight_id : null);
            })
            .catch(() => {});
    }

    // The open fight for this terminal's event changes over time (closes,
    // a new one opens) with no page navigation to hang a fresh subscription
    // off of, so this re-subscribes to whichever fight's pool is currently
    // live every time poll() sees it change.
    function subscribeFightChannel(fightId) {
        if (!window.Echo || subscribedFightId === fightId) return;
        subscribedFightId = fightId;
        if (fightId) window.Echo.channel('fight.' + fightId).listen('.BetPoolUpdated', poll);
    }

    window.addEventListener('echo:ready', () => {
        window.Echo.channel('kiosk.' + token)
            .listen('.KioskScanResult', (e) => {
                showBanner(e.success, e.message);
                if (numpadOpen) closeNumpad();
                resetBetStatus();
            });

        // A fight opening/closing for this event isn't scoped to one fight
        // channel (there's no fight to subscribe to yet the moment one
        // closes), so this listens at the event level and lets poll() above
        // work out which fight (if any) is live now.
        if (eventId) window.Echo.channel('event.' + eventId).listen('.FightStatusUpdated', poll);

        // Covers the fight already open at page load — the first poll()
        // call below can race Echo's own setup and skip this subscription
        // if it resolves first.
        subscribeFightChannel(@json($fight?->id));

        // No polling — pool changes broadcast on the fight channel and
        // opens/closes on the event channel — but a resync still runs on
        // reconnect in case something landed while the socket was down.
        window.onEchoReconnect(poll);
    });

    poll();
})();
</script>
@endpush
@endsection
