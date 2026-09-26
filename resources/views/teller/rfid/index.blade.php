@extends('layouts.app')

@section('title', __('RFID Cards'))

@section('content')
<div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
    <h1 class="text-2xl font-bold">{{ __('RFID Cards') }}</h1>
    <a href="{{ route('teller.dashboard') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Back to dashboard') }}</a>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6 max-w-md">
    <h2 class="font-semibold mb-3">{{ __('Scan player QR') }}</h2>
    <p class="text-sm text-slate-400 mb-3">
        {{ __('Scan the player\'s QR (from their "My QR Card" screen) with your phone\'s camera to open it directly, or type the code shown under their QR here.') }}
    </p>
    <form method="POST" action="{{ route('teller.rfid.lookup') }}" class="flex gap-2">
        @csrf
        <input type="text" name="code" required placeholder="{{ __('e.g. AB12CD3456') }}"
               class="flex-1 rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 uppercase">
        <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2">{{ __('Go') }}</button>
    </form>
</div>

<p class="text-center text-sm text-slate-500 max-w-md mx-auto mb-6">— {{ __('or') }} —</p>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6 max-w-md">
    <h2 class="font-semibold mb-3">{{ __('Link a card to a player') }}</h2>
    <p class="text-sm text-slate-400 mb-3">
        {{ __('Tap the card on any connected reader to fill in the tag ID field below, or type it in directly.') }}
    </p>
    <form method="POST" action="{{ route('teller.rfid.store') }}" class="space-y-3" autocomplete="off">
        @csrf
        <div class="relative">
            <label class="block text-sm text-slate-400 mb-1">{{ __('Player username') }}</label>
            <input type="text" name="username" id="username" required value="{{ old('username') }}"
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            <div id="username-suggestions" class="hidden absolute z-10 mt-1 w-full rounded-lg border border-slate-700 bg-slate-800 shadow-lg overflow-hidden"></div>
        </div>
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Tag ID') }}</label>
            <input type="text" name="tag_uid" id="tag_uid" required value="{{ old('tag_uid') }}"
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 font-mono">
        </div>
        <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold py-2">{{ __('Link Card') }}</button>
    </form>
</div>

@push('scripts')
<script>
(function () {
    const input = document.getElementById('username');
    const box = document.getElementById('username-suggestions');
    const searchUrl = @json(route('teller.rfid.search'));
    const hasCardLabel = @json(__('HAS CARD'));
    if (!input || !box) return;

    let debounceTimer = null;
    let activeIndex = -1;
    let items = [];

    function hide() {
        box.classList.add('hidden');
        box.innerHTML = '';
        items = [];
        activeIndex = -1;
    }

    function render(players) {
        items = players;
        activeIndex = -1;

        if (players.length === 0) {
            hide();
            return;
        }

        box.innerHTML = players.map((p, i) => `
            <button type="button" data-index="${i}" data-username="${p.username}"
                class="suggestion-row w-full text-left px-3 py-2 hover:bg-slate-700 transition flex items-center justify-between gap-2">
                <span>
                    <span class="font-semibold text-slate-100">${p.username}</span>
                    ${p.name ? `<span class="text-slate-500 text-xs"> — ${p.name}</span>` : ''}
                </span>
                ${p.has_card ? `<span class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-800 text-amber-100 whitespace-nowrap">${hasCardLabel}</span>` : ''}
            </button>
        `).join('');

        box.classList.remove('hidden');

        box.querySelectorAll('.suggestion-row').forEach((row) => {
            row.addEventListener('click', () => {
                input.value = row.dataset.username;
                hide();
                document.getElementById('tag_uid').focus();
            });
        });
    }

    function highlight() {
        box.querySelectorAll('.suggestion-row').forEach((row, i) => {
            row.classList.toggle('bg-slate-700', i === activeIndex);
        });
    }

    input.addEventListener('input', () => {
        const query = input.value.trim();
        clearTimeout(debounceTimer);

        if (query.length === 0) {
            hide();
            return;
        }

        debounceTimer = setTimeout(() => {
            fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } })
                .then(r => r.json())
                .then(render)
                .catch(() => {});
        }, 250);
    });

    input.addEventListener('keydown', (e) => {
        if (box.classList.contains('hidden')) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            highlight();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            highlight();
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            input.value = items[activeIndex].username;
            hide();
            document.getElementById('tag_uid').focus();
        } else if (e.key === 'Escape') {
            hide();
        }
    });

    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !box.contains(e.target)) hide();
    });
})();
</script>
@endpush

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
    <h2 class="font-semibold mb-1 px-1">{{ __('Recently linked') }}</h2>
    <div class="divide-y divide-slate-800/60">
        @forelse ($assigned as $player)
            <div class="flex items-center justify-between px-1 py-3 gap-3">
                <div class="min-w-0">
                    <p class="font-semibold text-slate-100 truncate">{{ $player->displayName() }}</p>
                    <p class="text-xs text-slate-500 font-mono truncate">{{ $player->rfid_uid }}</p>
                </div>
                <form method="POST" action="{{ route('teller.rfid.destroy', $player) }}" onsubmit="return confirm('{{ __('Unlink this card from :name?', ['name' => $player->displayName()]) }}');">
                    @csrf
                    @method('DELETE')
                    <button class="text-sm text-red-400 hover:underline whitespace-nowrap">{{ __('Unlink') }}</button>
                </form>
            </div>
        @empty
            <p class="py-4 px-1 text-slate-500 text-sm">{{ __('No cards linked yet.') }}</p>
        @endforelse
    </div>
</div>
@endsection
