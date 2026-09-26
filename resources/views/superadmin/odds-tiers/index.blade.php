@extends('layouts.app')

@section('title', __('Odds Tiers'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('Odds Tiers') }}</h1>

<p class="text-sm text-slate-400 mb-6 max-w-lg">
    {{ __('Meron:Wala matching ratios CombinedSabong players can pick from in Odds mode (e.g. "10-9" means a meron bettor is matched against 0.9x their stake from wala). Assign which of these an event offers when creating/editing it.') }}
</p>

<div class="space-y-3 max-w-lg">
    @foreach ($oddsTiers as $tier)
        <form method="POST" action="{{ route('superadmin.odds-tiers.update', $tier) }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 flex items-center gap-3">
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
        <form method="POST" action="{{ route('superadmin.odds-tiers.destroy', $tier) }}" onsubmit="return confirm('{{ __('Delete :label?', ['label' => $tier->label]) }}')" class="-mt-2 mb-1">
            @csrf
            @method('DELETE')
            <button class="text-xs text-red-400 hover:underline ml-4">{{ __('Delete') }}</button>
        </form>
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
@endsection
