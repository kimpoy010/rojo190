@extends('layouts.app')

@section('title', __('Cockpit Presets'))

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">{{ __('Cockpit Presets') }}</h1>
    <a href="{{ route('superadmin.cockpits.index') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Manage cockpits') }}</a>
</div>

<p class="text-sm text-slate-400 mb-6 max-w-lg">
    {{ __('A preset is a group of cockpits — pick one when creating an event instead of typing a stream URL by hand. The first cockpit in a preset previews the event until a fight gets its own cockpit assigned.') }}
</p>

@if ($cockpits->isEmpty())
    <p class="text-sm text-amber-400 max-w-lg mb-6">
        {{ __('No cockpits exist yet.') }}
        <a href="{{ route('superadmin.cockpits.index') }}" class="underline">{{ __('Add one first') }}</a>
    </p>
@endif

<div class="space-y-4 max-w-lg">
    @foreach ($presets as $preset)
        @php $presetCockpitIds = $preset->cockpits->pluck('id')->all(); @endphp
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <form method="POST" action="{{ route('superadmin.cockpit-presets.update', $preset) }}" class="space-y-3">
                @csrf
                @method('PUT')
                <div class="flex items-center justify-between gap-3">
                    <input name="name" value="{{ old('name', $preset->name) }}" required
                           class="font-semibold bg-transparent border-b border-transparent hover:border-slate-700 focus:border-red-500 focus:outline-none px-0 py-1 flex-1">
                    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-1.5 text-sm whitespace-nowrap">{{ __('Save') }}</button>
                </div>
                @if ($cockpits->isNotEmpty())
                    <div class="flex flex-wrap gap-3">
                        @foreach ($cockpits as $cockpit)
                            <label class="flex items-center gap-1.5 text-sm text-slate-300">
                                <input type="checkbox" name="cockpit_ids[]" value="{{ $cockpit->id }}"
                                       {{ in_array($cockpit->id, $presetCockpitIds, true) ? 'checked' : '' }} class="rounded">
                                {{ $cockpit->name }}
                            </label>
                        @endforeach
                    </div>
                @endif
            </form>
            <form method="POST" action="{{ route('superadmin.cockpit-presets.destroy', $preset) }}" onsubmit="return confirm('{{ __('Remove :name?', ['name' => $preset->name]) }}')">
                @csrf
                @method('DELETE')
                <button class="text-xs text-red-400 hover:underline">{{ __('Remove preset') }}</button>
            </form>
        </div>
    @endforeach
</div>

<form method="POST" action="{{ route('superadmin.cockpit-presets.store') }}" class="max-w-lg mt-6 rounded-xl border border-slate-800 bg-slate-900 p-4 space-y-3">
    @csrf
    <input name="name" required placeholder="{{ __('e.g. Araneta — 3 Ring Setup') }}"
           class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-sm">
    @if ($cockpits->isNotEmpty())
        <div class="flex flex-wrap gap-3">
            @foreach ($cockpits as $cockpit)
                <label class="flex items-center gap-1.5 text-sm text-slate-300">
                    <input type="checkbox" name="cockpit_ids[]" value="{{ $cockpit->id }}" class="rounded">
                    {{ $cockpit->name }}
                </label>
            @endforeach
        </div>
    @endif
    <button class="rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold px-4 py-2 text-sm">{{ __('Add preset') }}</button>
</form>
@endsection
