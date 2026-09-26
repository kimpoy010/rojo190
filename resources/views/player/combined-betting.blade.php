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
    $statusBadgeClass = fn (string $status) => match ($status) {
        'open' => 'bg-emerald-600 text-white',
        'last_call' => 'bg-yellow-500 text-slate-900',
        'closed' => 'bg-amber-600 text-white',
        default => 'bg-slate-700 text-slate-200',
    };
    $currency = $theme['currency'];
    $isBettable = in_array($fight->status, \App\Models\Fight::BETTABLE_STATUSES, true);
    $drawRemaining = max(0, $maxDrawBet - $drawPool);
@endphp

@section('content')
<div class="w-full sm:max-w-lg sm:mx-auto space-y-3">

    <!-- Top bar -->
    <div class="flex items-center justify-between bg-[#160e0a] rounded-t-xl px-4 py-3 border border-[#2a1a14]">
        <div class="flex items-center gap-2">
            <span class="text-emerald-400 font-bold text-sm">{{ __('Fight #') }} <span id="fight-number">{{ $fight->fight_number }}</span></span>
            <span id="fight-status-badge" class="text-[11px] px-2.5 py-0.5 rounded-full font-bold uppercase {{ $statusBadgeClass($fight->status) }}">
                {{ $statusCopy['label'] }}
            </span>
        </div>
        <span class="text-amber-400 font-extrabold text-lg" style="text-shadow: 0 0 16px rgba(251,191,36,0.25);">{{ $currency }}<span data-wallet-balance="main">{{ number_format($wallet->main_balance ?? 0, 2) }}</span></span>
    </div>

    <!-- Totalizer / Odds toggle -->
    <div class="flex items-center justify-center gap-3 bg-[#160e0a] border border-[#2a1a14] rounded-xl px-4 py-3">
        <button type="button" id="mode-label-pool" class="text-sm font-extrabold uppercase tracking-wide text-white">{{ __('Totalizer') }}</button>
        <button type="button" id="mode-toggle" class="relative w-14 h-7 rounded-full bg-slate-700 transition-colors" aria-pressed="false">
            <span id="mode-toggle-thumb" class="absolute top-0.5 left-0.5 w-6 h-6 rounded-full bg-white shadow transition-transform"></span>
        </button>
        <button type="button" id="mode-label-odds" class="text-sm font-extrabold uppercase tracking-wide text-slate-400">{{ __('Odds') }}</button>
    </div>

    <!-- Totalizer panel -->
    <div id="panel-pool" class="grid grid-cols-2 gap-3">
        <button type="button" class="place-pool-bet text-left rounded-xl p-4 bg-red-900/80 border border-red-700/50 relative overflow-hidden" data-side="meron">
            <span class="absolute top-2 left-2 text-[10px] font-bold bg-black/40 rounded-full px-2 py-0.5" data-pool-pct="meron">0%</span>
            <div class="mt-4 text-yellow-300 font-extrabold text-sm">{{ $event->sideLabel('meron') }}</div>
            <div class="text-slate-300 text-[10px] uppercase tracking-wide">{{ __('Payout') }}</div>
            <div class="text-white font-extrabold text-xl" data-pool-payout="meron">{{ number_format($payouts['meron'], 2) }}x</div>
            <div class="text-yellow-300 font-bold text-sm" data-pool-amount="meron">{{ $currency }}{{ number_format($poolTotals['meron'], 0) }}</div>
            <div class="text-slate-400 text-[11px]" data-pool-mine="meron">{{ __(':amount mine', ['amount' => $currency.number_format($myMeronPool, 0)]) }}</div>
            <div class="mt-2 rounded-lg bg-black/30 text-center text-[11px] font-bold uppercase py-1.5" data-bet-cta>{{ $isBettable ? __('Tap to bet') : __('Betting closed') }}</div>
        </button>

        <button type="button" class="place-pool-bet text-left rounded-xl p-4 bg-blue-900/80 border border-blue-700/50 relative overflow-hidden" data-side="wala">
            <span class="absolute top-2 left-2 text-[10px] font-bold bg-black/40 rounded-full px-2 py-0.5" data-pool-pct="wala">0%</span>
            <div class="mt-4 text-yellow-300 font-extrabold text-sm">{{ $event->sideLabel('wala') }}</div>
            <div class="text-slate-300 text-[10px] uppercase tracking-wide">{{ __('Payout') }}</div>
            <div class="text-white font-extrabold text-xl" data-pool-payout="wala">{{ number_format($payouts['wala'], 2) }}x</div>
            <div class="text-yellow-300 font-bold text-sm" data-pool-amount="wala">{{ $currency }}{{ number_format($poolTotals['wala'], 0) }}</div>
            <div class="text-slate-400 text-[11px]" data-pool-mine="wala">{{ __(':amount mine', ['amount' => $currency.number_format($myWalaPool, 0)]) }}</div>
            <div class="mt-2 rounded-lg bg-black/30 text-center text-[11px] font-bold uppercase py-1.5" data-bet-cta>{{ $isBettable ? __('Tap to bet') : __('Betting closed') }}</div>
        </button>
    </div>

    <!-- Odds panel -->
    <div id="panel-odds" class="hidden bg-[#160e0a] border border-[#2a1a14] rounded-xl overflow-hidden">
        <div class="grid grid-cols-3 text-center text-[10px] font-bold uppercase text-slate-400 bg-black/30 px-2 py-2">
            <span>{{ __('Total') }}</span>
            <span>{{ __('Odds') }}</span>
            <span>{{ __('Total') }}</span>
        </div>
        <div id="odds-tier-rows" class="divide-y divide-slate-800">
            @forelse ($oddsTiers as $tier)
                @php
                    $t = $tierTotals[$tier->id] ?? ['meron' => 0, 'wala' => 0, 'meron_avail' => 0, 'wala_avail' => 0];
                    $mine = fn ($side) => $myTierBets[$tier->id.'-'.$side] ?? 0;
                @endphp
                <div class="grid grid-cols-3 items-center text-center px-2 py-2.5" data-tier-row="{{ $tier->id }}">
                    <button type="button" class="place-odds-bet flex flex-col items-center gap-0.5" data-side="meron" data-tier="{{ $tier->id }}">
                        <span class="text-red-400 font-bold text-sm" data-tier-total="meron">{{ number_format($t['meron'], 0) }}</span>
                        <span class="text-[10px] text-slate-500" data-tier-mine="meron">{{ __('Mine: :amount', ['amount' => number_format($mine('meron'), 0)]) }}</span>
                        <span class="text-[10px] text-emerald-400" data-tier-avail="meron" @if (($t['meron_avail'] ?? 0) <= 0) hidden @endif>{{ __('Avail: :amount', ['amount' => number_format($t['meron_avail'] ?? 0, 0)]) }}</span>
                    </button>
                    <span class="text-white font-extrabold text-xs">{{ rtrim(rtrim((string) $tier->meron_ratio, '0'), '.') }}-{{ rtrim(rtrim((string) $tier->wala_ratio, '0'), '.') }}</span>
                    <button type="button" class="place-odds-bet flex flex-col items-center gap-0.5" data-side="wala" data-tier="{{ $tier->id }}">
                        <span class="text-blue-400 font-bold text-sm" data-tier-total="wala">{{ number_format($t['wala'], 0) }}</span>
                        <span class="text-[10px] text-slate-500" data-tier-mine="wala">{{ __('Mine: :amount', ['amount' => number_format($mine('wala'), 0)]) }}</span>
                        <span class="text-[10px] text-emerald-400" data-tier-avail="wala" @if (($t['wala_avail'] ?? 0) <= 0) hidden @endif>{{ __('Avail: :amount', ['amount' => number_format($t['wala_avail'] ?? 0, 0)]) }}</span>
                    </button>
                </div>
            @empty
                <div class="px-4 py-6 text-center text-slate-500 text-sm">{{ __('No odds tiers configured for this event yet.') }}</div>
            @endforelse
        </div>
    </div>

    <!-- Draw bar -->
    @if ($fight->draw_enabled)
        <button type="button" id="draw-bet-btn" data-side="draw" class="w-full text-left rounded-xl px-4 py-3 bg-emerald-700 hover:bg-emerald-600 transition">
            <div class="flex items-center justify-between text-white font-extrabold text-sm">
                <span>{{ __('DRAW :ratio', ['ratio' => '1:'.number_format($drawMultiplier, 0)]) }} | <span data-draw-amount>{{ $currency }}{{ number_format($drawPool, 0) }}</span></span>
                <span class="text-[11px] bg-black/30 rounded-full px-2 py-1 uppercase" data-bet-cta>{{ $isBettable ? __('Tap to bet') : __('Closed') }}</span>
            </div>
            <div class="text-emerald-100 text-[11px]" data-draw-remaining>{{ __(':remaining left of :max pool', ['remaining' => $currency.number_format($drawRemaining, 0), 'max' => $currency.number_format($maxDrawBet, 0)]) }} ({{ __(':amount mine', ['amount' => $currency.number_format($myDrawBet, 0)]) }})</div>
        </button>
    @endif

    <!-- My unmatched odds bets — only a bet still sitting on the order
         book (nobody's taken the other side yet, in full) can be
         cancelled; a matched bet is locked in. -->
    @if ($myUnmatchedBets->isNotEmpty())
        <div id="my-unmatched-bets" class="rounded-xl bg-slate-900 border border-slate-800 divide-y divide-slate-800">
            <div class="px-4 py-2 text-[11px] font-bold uppercase text-slate-500">{{ __('My unmatched odds bets') }}</div>
            @foreach ($myUnmatchedBets as $bet)
                <div class="flex items-center justify-between px-4 py-2.5 text-sm" data-unmatched-bet-row="{{ $bet->id }}">
                    <span class="capitalize">
                        {{ $bet->side }} @ {{ rtrim(rtrim((string) $bet->oddsTier->meron_ratio, '0'), '.') }}-{{ rtrim(rtrim((string) $bet->oddsTier->wala_ratio, '0'), '.') }}
                        <span class="text-slate-500">({{ $currency }}{{ number_format($bet->unmatched_amount, 2) }} unmatched)</span>
                    </span>
                    <button type="button" class="cancel-unmatched-bet text-xs text-red-400 hover:underline" data-bet-id="{{ $bet->id }}">{{ __('Cancel') }}</button>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Status panel -->
    <div class="rounded-xl bg-slate-900 border border-slate-800 px-4 py-4 text-center" id="fight-status-panel">
        <div class="font-bold uppercase text-sm {{ $fight->status === 'open' ? 'text-emerald-400' : 'text-amber-400' }}" id="fight-status-label">{{ $statusCopy['label'] }}</div>
        <div class="text-slate-400 text-xs mt-1" id="fight-status-desc">{{ $statusCopy['desc'] }}</div>
    </div>
</div>

<script>
(function () {
    const fightId = @json($fight->id);
    const statusUrl = @json(route('play.combined-fight.status', $fight));
    const betUrl = @json(route('play.combined-bet', $fight));
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const currency = @json($currency);
    const statusCopy = @json(collect($statusCopyMap)->map(fn ($c) => [$c['label'], $c['desc']]));
    const swalDark = { background: '#160e0a', color: '#f5efe9' };

    let mode = 'pool'; // 'pool' | 'odds'
    let isBettable = @json($isBettable);

    const toggle = document.getElementById('mode-toggle');
    const thumb = document.getElementById('mode-toggle-thumb');
    const labelPool = document.getElementById('mode-label-pool');
    const labelOdds = document.getElementById('mode-label-odds');
    const panelPool = document.getElementById('panel-pool');
    const panelOdds = document.getElementById('panel-odds');

    function applyMode() {
        panelPool.classList.toggle('hidden', mode !== 'pool');
        panelOdds.classList.toggle('hidden', mode !== 'odds');
        thumb.style.transform = mode === 'odds' ? 'translateX(26px)' : 'translateX(0)';
        toggle.setAttribute('aria-pressed', mode === 'odds' ? 'true' : 'false');
        labelPool.classList.toggle('text-white', mode === 'pool');
        labelPool.classList.toggle('text-slate-400', mode !== 'pool');
        labelOdds.classList.toggle('text-white', mode === 'odds');
        labelOdds.classList.toggle('text-slate-400', mode !== 'odds');
    }

    toggle.addEventListener('click', () => {
        mode = mode === 'pool' ? 'odds' : 'pool';
        applyMode();
    });
    applyMode();

    function fmt(n) {
        return Math.round(n).toLocaleString();
    }

    function promptAmount(title, color, onConfirm) {
        if (!isBettable) return;
        const chips = [10, 20, 50, 100, 500, 1000];
        const chipsHtml = chips.map(a => `<button type="button" class="amt-chip" data-amount="${a}" style="padding:6px 0;border-radius:8px;border:1px solid #3a241b;background:#0b0705;color:#c9baaf;font-size:12px;">${a}</button>`).join('');

        Swal.fire({
            title,
            html:
                `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:10px;">${chipsHtml}</div>` +
                `<input id="swal-amount" type="number" min="1" placeholder="{{ __('Amount') }}" style="width:100%;box-sizing:border-box;background:#0b0705;border:1px solid #2a1a14;border-radius:9px;padding:9px 12px;color:#f5efe9;font-size:14px;">`,
            showCancelButton: true,
            confirmButtonText: '{{ __('Confirm bet') }}',
            cancelButtonText: '{{ __('Cancel') }}',
            confirmButtonColor: color,
            ...swalDark,
            didOpen: () => {
                const input = document.getElementById('swal-amount');
                document.querySelectorAll('.amt-chip').forEach(chip => {
                    chip.addEventListener('click', () => { input.value = chip.dataset.amount; });
                });
            },
            preConfirm: () => {
                const value = parseFloat(document.getElementById('swal-amount').value);
                if (!value || value <= 0) {
                    Swal.showValidationMessage('{{ __('Enter a valid amount.') }}');
                    return false;
                }
                return value;
            },
        }).then(result => {
            if (result.isConfirmed) onConfirm(result.value);
        });
    }

    function placeBet(payload) {
        fetch(betUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify(payload),
        })
            .then(r => r.json().then(data => ({ ok: r.ok, data })))
            .then(({ ok, data }) => {
                if (!ok || !data.success) {
                    Swal.fire({ title: '{{ __('Could not place bet') }}', text: data.message || '{{ __('Something went wrong.') }}', icon: 'error', confirmButtonColor: '#dc2626', ...swalDark });
                    return;
                }
                if (data.wallet_balance !== undefined) {
                    document.querySelector('[data-wallet-balance="main"]').textContent = Number(data.wallet_balance).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                Swal.fire({ title: '{{ __('Bet placed') }}', text: '{{ __('Bet placed successfully.') }}', icon: 'success', timer: 2000, timerProgressBar: true, confirmButtonColor: '#16a34a', ...swalDark });
            })
            .catch(() => {
                Swal.fire({ title: '{{ __('Network error') }}', text: '{{ __('Could not reach the server. Please try again.') }}', icon: 'error', confirmButtonColor: '#dc2626', ...swalDark });
            });
    }

    document.querySelectorAll('.place-pool-bet').forEach(btn => {
        btn.addEventListener('click', () => {
            const side = btn.dataset.side;
            promptAmount('{{ __('Enter amount') }}', side === 'meron' ? '#dc2626' : '#2563eb', amount => {
                placeBet({ mode: 'pool', side, amount });
            });
        });
    });

    document.querySelectorAll('.place-odds-bet').forEach(btn => {
        btn.addEventListener('click', () => {
            const side = btn.dataset.side;
            const tier = btn.dataset.tier;
            promptAmount('{{ __('Enter amount') }}', side === 'meron' ? '#dc2626' : '#2563eb', amount => {
                placeBet({ mode: 'odds', side, amount, odds_tier_id: tier });
            });
        });
    });

    const drawBtn = document.getElementById('draw-bet-btn');
    if (drawBtn) {
        drawBtn.addEventListener('click', () => {
            promptAmount('{{ __('Enter amount') }}', '#16a34a', amount => {
                placeBet({ mode, side: 'draw', amount });
            });
        });
    }

    document.querySelectorAll('.cancel-unmatched-bet').forEach(btn => {
        btn.addEventListener('click', () => {
            const betId = btn.dataset.betId;
            const cancelUrl = @json(route('play.combined-cancel-bet', ['fight' => $fight, 'bet' => '__BET_ID__'])).replace('__BET_ID__', betId);

            btn.disabled = true;
            fetch(cancelUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            })
                .then(r => r.json().then(data => ({ ok: r.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok || !data.success) {
                        Swal.fire({ title: '{{ __('Could not cancel bet') }}', text: data.message || '{{ __('Something went wrong.') }}', icon: 'error', confirmButtonColor: '#dc2626', ...swalDark });
                        btn.disabled = false;
                        return;
                    }
                    const row = document.querySelector(`[data-unmatched-bet-row="${betId}"]`);
                    if (row) row.remove();
                    const container = document.getElementById('my-unmatched-bets');
                    if (container && !container.querySelector('[data-unmatched-bet-row]')) container.remove();
                    poll();
                })
                .catch(() => {
                    Swal.fire({ title: '{{ __('Network error') }}', text: '{{ __('Could not reach the server. Please try again.') }}', icon: 'error', confirmButtonColor: '#dc2626', ...swalDark });
                    btn.disabled = false;
                });
        });
    });

    function applyStatus(data) {
        document.getElementById('fight-status-badge').textContent = (statusCopy[data.status] || [data.status.toUpperCase()])[0];
        const [label, desc] = statusCopy[data.status] || [data.status.toUpperCase(), ''];
        document.getElementById('fight-status-label').textContent = label;
        document.getElementById('fight-status-desc').textContent = desc;

        isBettable = data.status === 'open' || data.status === 'last_call';
        document.querySelectorAll('[data-bet-cta]').forEach(el => {
            el.textContent = isBettable ? '{{ __('Tap to bet') }}' : '{{ __('Closed') }}';
        });

        document.querySelector('[data-pool-payout="meron"]').textContent = Number(data.meron_payout).toFixed(2) + 'x';
        document.querySelector('[data-pool-payout="wala"]').textContent = Number(data.wala_payout).toFixed(2) + 'x';
        document.querySelector('[data-pool-amount="meron"]').textContent = currency + fmt(data.meron_pool);
        document.querySelector('[data-pool-amount="wala"]').textContent = currency + fmt(data.wala_pool);
        document.querySelector('[data-pool-pct="meron"]').textContent = Number(data.meron_payout_pct || 0).toFixed(0) + '%';
        document.querySelector('[data-pool-pct="wala"]').textContent = Number(data.wala_payout_pct || 0).toFixed(0) + '%';

        const drawAmountEl = document.querySelector('[data-draw-amount]');
        if (drawAmountEl) drawAmountEl.textContent = currency + fmt(data.draw_pool);

        Object.entries(data.tier_totals || {}).forEach(([tierId, totals]) => {
            const row = document.querySelector(`[data-tier-row="${tierId}"]`);
            if (!row) return;
            row.querySelector('[data-tier-total="meron"]').textContent = fmt(totals.meron);
            row.querySelector('[data-tier-total="wala"]').textContent = fmt(totals.wala);

            ['meron', 'wala'].forEach((side) => {
                const availEl = row.querySelector(`[data-tier-avail="${side}"]`);
                if (!availEl) return;
                const avail = Number(totals[side + '_avail'] || 0);
                availEl.hidden = avail <= 0;
                if (avail > 0) availEl.textContent = '{{ __('Avail:') }} ' + fmt(avail);
            });
        });

        if (['declared', 'cancelled'].includes(data.status)) {
            setTimeout(() => window.location.reload(), 1500);
        }
    }

    function poll() {
        fetch(statusUrl, { headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(applyStatus)
            .catch(() => {});
    }

    setInterval(poll, 5000);

    if (window.Echo) {
        window.Echo.channel('fight.' + fightId)
            .listen('.BetPoolUpdated', (e) => {
                applyStatus({
                    status: @json($fight->status),
                    meron_pool: e.meron_pool,
                    wala_pool: e.wala_pool,
                    draw_pool: e.draw_pool,
                    meron_payout: e.meron_payout,
                    wala_payout: e.wala_payout,
                    meron_payout_pct: e.meron_payout_pct,
                    wala_payout_pct: e.wala_payout_pct,
                    tier_totals: e.tier_totals || {},
                });
            })
            .listen('.FightStatusUpdated', () => poll());
    }
})();
</script>
@endsection
