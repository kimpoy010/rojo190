@extends('layouts.app')

@section('title', __('Edit :name', ['name' => $event->name]))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('Edit Event') }}</h1>

<form method="POST" action="{{ route('superadmin.events.update', $event) }}" enctype="multipart/form-data" class="max-w-lg space-y-4">
    @csrf
    @method('PUT')

    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Event name') }}</label>
        <input name="name" value="{{ old('name', $event->name) }}" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Arena') }}</label>
        <input name="arena" value="{{ old('arena', $event->arena) }}" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Date/time') }}</label>
        <input type="datetime-local" name="date" value="{{ old('date', $event->date?->format('Y-m-d\TH:i')) }}" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Cockpit preset (optional)') }}</label>
        <select name="cockpit_preset_id" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            <option value="">{{ __('None') }}</option>
            @foreach ($cockpitPresets as $preset)
                <option value="{{ $preset->id }}" {{ (string) old('cockpit_preset_id', $event->cockpit_preset_id) === (string) $preset->id ? 'selected' : '' }}>{{ $preset->name }}</option>
            @endforeach
        </select>
        <p class="text-xs text-slate-500 mt-1">
            {{ __('Which cockpits (camera feeds) this event can use.') }}
            <a href="{{ route('superadmin.cockpit-presets.index') }}" class="underline">{{ __('Manage presets') }}</a>
        </p>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Thumbnail image (optional)') }}</label>
        @if ($event->thumbnail_url)
            <div class="flex items-center gap-3 mb-2">
                <img src="{{ $event->thumbnail_url }}" alt="" class="h-24 w-40 object-cover rounded-lg border border-slate-800">
                <label class="flex items-center gap-2 text-sm text-slate-400">
                    <input type="checkbox" name="remove_thumbnail" value="1" class="rounded">
                    {{ __('Remove current image') }}
                </label>
            </div>
        @endif
        <input type="file" name="thumbnail" accept="image/*" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
        <p class="text-xs text-slate-500 mt-1">{{ __("Shown on the player events list card. Falls back to the game's default banner when left blank.") }}</p>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Display multiplier') }}</label>
        <input type="number" step="0.01" name="multiplier" value="{{ old('multiplier', $event->multiplier) }}" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Bet limit ($, optional)') }}</label>
        <input type="text" inputmode="decimal" name="bet_limit" value="{{ old('bet_limit', $event->bet_limit) }}" class="amount-input w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div class="flex items-center gap-2">
        <input type="checkbox" name="draw_enabled" id="draw_enabled" value="1" {{ old('draw_enabled', $event->draw_enabled) ? 'checked' : '' }} class="rounded">
        <label for="draw_enabled" class="text-sm text-slate-400">{{ __('Draw betting enabled') }}</label>
    </div>

    @if ($event->game?->isCombined())
        @php $selectedTierIds = old('odds_tier_ids', $event->oddsTiers->pluck('id')->all()); @endphp
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Odds tiers offered (optional — leave all unchecked to offer every active tier)') }}</label>
            <div class="grid grid-cols-3 gap-2">
                @foreach ($oddsTiers as $tier)
                    <label class="flex items-center gap-1.5 text-xs bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5">
                        <input type="checkbox" name="odds_tier_ids[]" value="{{ $tier->id }}" {{ in_array($tier->id, $selectedTierIds) ? 'checked' : '' }} class="rounded">
                        {{ $tier->label }}
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-slate-500 mt-1">
                <a href="{{ route('superadmin.odds-tiers.index') }}" class="underline">{{ __('Manage odds tiers') }}</a>
            </p>
        </div>
    @endif

    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-6 py-2">{{ __('Save changes') }}</button>
</form>
@endsection
