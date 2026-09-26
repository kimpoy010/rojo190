@extends('layouts.app')

@section('title', __(':name — Balance Check', ['name' => $terminal->name]))

@section('content')
<!-- The shared layout's <main> caps at max-w-6xl — a kiosk display should
     use the full screen, so override it for just this page. -->
<style>main { max-width: 100% !important; padding-left: 1rem !important; padding-right: 1rem !important; }</style>
<div class="w-full max-w-xl mx-auto space-y-6 py-8 text-center">

    <div class="bg-slate-900 rounded-xl px-4 py-3 border border-slate-800">
        <p class="font-bold text-lg text-red-400">{{ $terminal->name }}</p>
        <p class="text-xs text-slate-500">{{ __('Balance Check') }}</p>
    </div>

    <!-- Result banner — filled in live by a 'balance' reader's tap. Shown
         with room to breathe since a balance check has nothing else on
         screen competing for attention, unlike the betting kiosk. -->
    <div id="result-banner" class="hidden rounded-2xl px-6 py-10 text-center">
        <p id="result-player" class="text-2xl font-bold mb-2"></p>
        <p id="result-amount" class="text-[56pt] font-extrabold leading-none"></p>
        <p class="text-sm text-slate-500 mt-3">{{ __('Available balance') }}</p>
    </div>

    <div id="idle-hint" class="rounded-2xl border-2 border-dashed border-slate-700 px-6 py-16">
        <p class="text-5xl mb-4">💳</p>
        <p class="text-xl font-semibold text-slate-300">{{ __('Tap your card to check your balance') }}</p>
    </div>

    <div id="error-banner" class="hidden rounded-xl px-4 py-3 text-center font-semibold bg-red-900/60 border border-red-700 text-red-200"></div>

    <!-- Fallback for no card / unreachable reader: the code printed under
         a player's profile QR, typed by hand or scanned straight into this
         field by a handheld barcode scanner. -->
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs text-slate-500 mb-2">{{ __('No card? Scan or enter your profile QR reference ID instead.') }}</p>
        <form id="code-form" class="flex gap-2">
            <input type="text" id="code-input" name="code" autofocus autocomplete="off" placeholder="{{ __('e.g. AB12CD34EF') }}"
                   class="flex-1 rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 uppercase text-center tracking-widest">
            <button type="submit" class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2 text-sm">{{ __('Check') }}</button>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const token = @json($terminal->token);
    const lookupUrl = @json(route('kiosk.balance.lookup', $terminal));
    const csrf = @json(csrf_token());
    const i18n = { networkError: @json(__('Could not reach the server. Please try again.')) };

    const resultBanner = document.getElementById('result-banner');
    const resultPlayer = document.getElementById('result-player');
    const resultAmount = document.getElementById('result-amount');
    const idleHint = document.getElementById('idle-hint');
    const errorBanner = document.getElementById('error-banner');
    let resetTimeout = null;

    function showResult(success, message, details) {
        clearTimeout(resetTimeout);

        if (success && 'available_balance' in details) {
            resultPlayer.textContent = details.player || '';
            resultAmount.textContent = @json($currencySymbol) + Number(details.available_balance).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            resultBanner.classList.remove('hidden');
            resultBanner.className = 'rounded-2xl px-6 py-10 text-center bg-emerald-900/40 border border-emerald-700';
            idleHint.classList.add('hidden');
            errorBanner.classList.add('hidden');
        } else {
            errorBanner.textContent = message;
            errorBanner.classList.remove('hidden');
            resultBanner.classList.add('hidden');
            idleHint.classList.add('hidden');
        }

        // Back to the idle prompt after a while so the next person isn't
        // greeted by the previous tapper's balance still on screen.
        resetTimeout = setTimeout(() => {
            resultBanner.classList.add('hidden');
            errorBanner.classList.add('hidden');
            idleHint.classList.remove('hidden');
        }, 10000);
    }

    window.addEventListener('echo:ready', () => {
        window.Echo.channel('kiosk.' + token)
            .listen('.KioskScanResult', (e) => showResult(e.success, e.message, e.details || {}));
    });

    const codeForm = document.getElementById('code-form');
    const codeInput = document.getElementById('code-input');

    codeForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const code = codeInput.value.trim();
        if (!code) return;

        fetch(lookupUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            body: JSON.stringify({ code }),
        })
            .then(r => r.json().then(data => ({ ok: r.ok, data })))
            .then(({ data }) => showResult(data.success, data.message, data.details || {}))
            .catch(() => showResult(false, i18n.networkError, {}));

        codeInput.value = '';
    });
})();
</script>
@endpush
@endsection
