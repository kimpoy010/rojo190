@extends('layouts.app')

@section('title', __('Odds Tiers'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('Odds Tiers') }}</h1>

<p class="text-sm text-slate-400 mb-6 max-w-lg">
    {{ __('Meron:Wala matching ratios CombinedSabong players can pick from in Odds mode (e.g. "10-9" means a meron bettor is matched against 0.9x their stake from wala). Assign which of these an event offers when creating/editing it. Drag the handle to reorder — the order here is the order shown on the player\'s Odds table.') }}
</p>

<div id="odds-tier-list" class="space-y-3 max-w-lg">
    @foreach ($oddsTiers as $tier)
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-4" data-tier-row data-tier-id="{{ $tier->id }}">
            <div class="flex items-center gap-3">
                <span class="drag-handle cursor-grab active:cursor-grabbing text-slate-500 hover:text-slate-300 select-none text-lg leading-none touch-none" style="touch-action: none;" title="{{ __('Drag to reorder') }}">⠿</span>
                <form method="POST" action="{{ route('superadmin.odds-tiers.update', $tier) }}" class="flex items-center gap-3 flex-1 min-w-0">
                    @csrf
                    @method('PUT')
                    <input name="label" value="{{ old('label', $tier->label) }}" required class="w-20 rounded-lg bg-slate-800 border border-slate-700 px-2 py-1.5 text-sm">
                    <input type="number" step="0.01" name="meron_ratio" value="{{ old('meron_ratio', $tier->meron_ratio) }}" required class="w-20 rounded-lg bg-slate-800 border border-slate-700 px-2 py-1.5 text-sm" placeholder="{{ __('Meron') }}">
                    <span class="text-slate-500">:</span>
                    <input type="number" step="0.01" name="wala_ratio" value="{{ old('wala_ratio', $tier->wala_ratio) }}" required class="w-20 rounded-lg bg-slate-800 border border-slate-700 px-2 py-1.5 text-sm" placeholder="{{ __('Wala') }}">
                    <label class="flex items-center gap-1.5 text-xs text-slate-400 whitespace-nowrap">
                        <input type="checkbox" name="is_active" value="1" {{ $tier->is_active ? 'checked' : '' }} class="rounded">
                        {{ __('Active') }}
                    </label>
                    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-3 py-1.5 text-xs whitespace-nowrap">{{ __('Save') }}</button>
                </form>
            </div>
            <form method="POST" action="{{ route('superadmin.odds-tiers.destroy', $tier) }}" onsubmit="return confirm('{{ __('Delete :label?', ['label' => $tier->label]) }}')" class="mt-1 pl-8">
                @csrf
                @method('DELETE')
                <button class="text-xs text-red-400 hover:underline">{{ __('Delete') }}</button>
            </form>
        </div>
    @endforeach
</div>

<form method="POST" action="{{ route('superadmin.odds-tiers.store') }}" class="max-w-lg mt-6 rounded-xl border border-slate-800 bg-slate-900 p-4 flex items-center gap-3">
    @csrf
    <input name="label" required placeholder="{{ __('e.g. 10-9') }}" class="w-20 rounded-lg bg-slate-800 border border-slate-700 px-2 py-1.5 text-sm">
    <input type="number" step="0.01" name="meron_ratio" required placeholder="{{ __('Meron') }}" class="w-20 rounded-lg bg-slate-800 border border-slate-700 px-2 py-1.5 text-sm">
    <span class="text-slate-500">:</span>
    <input type="number" step="0.01" name="wala_ratio" required placeholder="{{ __('Wala') }}" class="w-20 rounded-lg bg-slate-800 border border-slate-700 px-2 py-1.5 text-sm">
    <button class="rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold px-4 py-2 text-sm">{{ __('Add tier') }}</button>
</form>

<script>
(function () {
    const list = document.getElementById('odds-tier-list');
    if (!list) return;

    const reorderUrl = @json(route('superadmin.odds-tiers.reorder'));
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    // Manual pointer-based drag rather than native HTML5 drag-and-drop —
    // the native API's drag initiation is unreliable across browsers/
    // trackpads/touch (and doubly so when toggling `draggable` on
    // mousedown of a child element), so this tracks the pointer directly
    // and reorders the DOM by comparing Y position against sibling rows.
    let draggingRow = null;
    // Everything below is in viewport coordinates (getBoundingClientRect),
    // consistently, to avoid mixing offset-parent-relative and scroll-
    // relative coordinate systems.
    let startY = 0;
    let origTop = 0;

    // Move/up listeners live on window, not the handle — pointer capture
    // isn't reliable enough across browsers/devices (and test tooling) to
    // depend on the handle itself still receiving events once the cursor
    // has moved elsewhere, so this tracks the drag globally instead and
    // only cares which handle started it.
    function onMove(e) {
        if (!draggingRow) return;
        const deltaY = e.clientY - startY;
        draggingRow.style.transform = `translateY(${deltaY}px)`;

        const draggingRect = draggingRow.getBoundingClientRect();
        const visualMidY = origTop + deltaY + draggingRect.height / 2;

        const rows = Array.from(list.querySelectorAll('[data-tier-row]')).filter(r => r !== draggingRow);
        for (const row of rows) {
            const rect = row.getBoundingClientRect();
            const rowMidY = rect.top + rect.height / 2;
            const draggingIsAfter = !!(row.compareDocumentPosition(draggingRow) & Node.DOCUMENT_POSITION_FOLLOWING);

            if (draggingIsAfter && visualMidY < rowMidY) {
                list.insertBefore(draggingRow, row);
                origTop = row.getBoundingClientRect().top; // dragging row now sits where `row` was
                break;
            }
            if (!draggingIsAfter && visualMidY > rowMidY) {
                list.insertBefore(draggingRow, row.nextSibling);
                origTop = row.getBoundingClientRect().top - draggingRect.height;
                break;
            }
        }
    }

    function onEnd() {
        if (!draggingRow) return;
        draggingRow.style.transform = '';
        draggingRow.style.position = '';
        draggingRow.classList.remove('z-10', 'shadow-lg');
        draggingRow = null;
        persistOrder();
    }

    list.querySelectorAll('.drag-handle').forEach(handle => {
        handle.addEventListener('pointerdown', (e) => {
            const row = handle.closest('[data-tier-row]');
            draggingRow = row;
            startY = e.clientY;
            origTop = row.getBoundingClientRect().top;
            row.style.position = 'relative';
            row.classList.add('z-10', 'shadow-lg');
            e.preventDefault();
        });
    });

    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onEnd);
    window.addEventListener('pointercancel', onEnd);

    function persistOrder() {
        const ids = Array.from(list.querySelectorAll('[data-tier-row]')).map(r => r.dataset.tierId);
        fetch(reorderUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ ids }),
        }).catch(() => {});
    }
})();
</script>
@endsection
