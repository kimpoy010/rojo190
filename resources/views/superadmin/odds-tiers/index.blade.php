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
                <span class="drag-handle cursor-grab active:cursor-grabbing text-slate-500 hover:text-slate-300 select-none text-lg leading-none" title="{{ __('Drag to reorder') }}">⠿</span>
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
    let dragging = null;

    list.querySelectorAll('[data-tier-row]').forEach(row => {
        const handle = row.querySelector('.drag-handle');
        row.draggable = false;
        handle.addEventListener('mousedown', () => { row.draggable = true; });
        row.addEventListener('dragend', () => { row.draggable = false; row.classList.remove('opacity-50'); });

        row.addEventListener('dragstart', (e) => {
            dragging = row;
            row.classList.add('opacity-50');
            e.dataTransfer.effectAllowed = 'move';
        });

        row.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (!dragging || dragging === row) return;
            const rect = row.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            list.insertBefore(dragging, before ? row : row.nextSibling);
        });

        row.addEventListener('drop', (e) => {
            e.preventDefault();
            persistOrder();
        });
    });

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
