@extends('layouts.app')

@section('title', __(':name — Teller Station', ['name' => $terminal->name]))

@section('content')
<div class="max-w-md mx-auto space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">{{ $terminal->name }}</h1>
        <a href="{{ route('teller.dashboard') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Back to dashboard') }}</a>
    </div>

    <!-- Waiting state -->
    <div id="waiting-block" class="rounded-xl border border-slate-800 bg-slate-900 p-8 text-center">
        <p class="text-slate-400">{{ __('Waiting for a card tap...') }}</p>
        <p class="text-xs text-slate-600 mt-1">{{ __("Tap the player's card on this station's reader to identify them.") }}</p>
    </div>

    <!-- Identified player -->
    <div id="player-block" class="hidden rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs uppercase text-slate-500 mb-1">{{ __('Player') }}</p>
        <p class="font-bold text-xl mb-1" id="player-name"></p>
        <p class="text-sm text-slate-400 mb-4">{{ __('Wallet balance:') }} $<span id="player-balance"></span></p>

        <form id="deposit-form" method="POST" class="flex gap-2 mb-3">
            @csrf
            <input type="text" inputmode="numeric" name="amount" data-decimals="0" placeholder="{{ __('Amount') }}" required
                   class="amount-input flex-1 rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            <button class="rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold px-4 py-2">{{ __('Deposit') }}</button>
        </form>

        <form id="withdraw-form" method="POST" onsubmit="return confirm('{{ __("Withdraw this player's full available balance and hand over the cash?") }}');">
            @csrf
            <button class="w-full rounded-lg bg-sky-600 hover:bg-sky-500 transition font-semibold py-2">{{ __('Withdraw full available balance') }}</button>
        </form>

        <button type="button" id="clear-btn" class="w-full text-center text-sm text-slate-500 hover:text-white transition mt-3">{{ __('Tap another card') }}</button>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const token = @json($terminal->token);
    const depositUrlTemplate = @json(route('teller.station.deposit', [$terminal, '__PLAYER__']));
    const withdrawUrlTemplate = @json(route('teller.station.withdraw', [$terminal, '__PLAYER__']));

    const waitingBlock = document.getElementById('waiting-block');
    const playerBlock = document.getElementById('player-block');
    const depositForm = document.getElementById('deposit-form');
    const withdrawForm = document.getElementById('withdraw-form');

    function showPlayer(data) {
        document.getElementById('player-name').textContent = data.display_name;
        document.getElementById('player-balance').textContent = Number(data.wallet_balance).toLocaleString(undefined, { minimumFractionDigits: 2 });
        depositForm.action = depositUrlTemplate.replace('__PLAYER__', data.player_id);
        withdrawForm.action = withdrawUrlTemplate.replace('__PLAYER__', data.player_id);
        waitingBlock.classList.add('hidden');
        playerBlock.classList.remove('hidden');
    }

    document.getElementById('clear-btn').addEventListener('click', () => {
        playerBlock.classList.add('hidden');
        waitingBlock.classList.remove('hidden');
    });

    window.addEventListener('echo:ready', () => {
        window.Echo.channel('kiosk.' + token)
            .listen('.PlayerIdentified', showPlayer);
    });
})();
</script>
@endpush
@endsection
