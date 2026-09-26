@extends('layouts.app')

@section('title', __('New event'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('New Event') }}</h1>

<form method="POST" action="{{ route('superadmin.events.store') }}" enctype="multipart/form-data" class="max-w-lg space-y-4">
    @csrf

    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Game') }}</label>
        <select name="game_id" id="game_id" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            @foreach ($games as $gameOption)
                <option value="{{ $gameOption->id }}" data-combined="{{ $gameOption->isCombined() ? '1' : '0' }}" {{ (string) old('game_id') === (string) $gameOption->id ? 'selected' : '' }}>{{ $gameOption->display_name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Event name') }}</label>
        <input name="name" value="{{ old('name') }}" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Arena') }}</label>
        <input name="arena" value="{{ old('arena') }}" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Date/time') }}</label>
        <input type="datetime-local" name="date" value="{{ old('date') }}" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Cockpit preset (optional)') }}</label>
        <select name="cockpit_preset_id" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            <option value="">{{ __('None') }}</option>
            @foreach ($cockpitPresets as $preset)
                <option value="{{ $preset->id }}" {{ (string) old('cockpit_preset_id') === (string) $preset->id ? 'selected' : '' }}>{{ $preset->name }}</option>
            @endforeach
        </select>
        <p class="text-xs text-slate-500 mt-1">
            {{ __('Which cockpits (camera feeds) this event can use.') }}
            <a href="{{ route('superadmin.cockpit-presets.index') }}" class="underline">{{ __('Manage presets') }}</a>
        </p>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Thumbnail image (optional)') }}</label>
        <input type="file" name="thumbnail" accept="image/*" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
        <p class="text-xs text-slate-500 mt-1">{{ __("Shown on the player events list card. Falls back to the game's default banner when left blank.") }}</p>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Display multiplier') }}</label>
        <input type="number" step="0.01" name="multiplier" value="{{ old('multiplier', 1) }}" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Bet limit ($, optional)') }}</label>
        <input type="text" inputmode="decimal" name="bet_limit" value="{{ old('bet_limit') }}" class="amount-input w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div class="flex items-center gap-2">
        <input type="checkbox" name="draw_enabled" id="draw_enabled" value="1" {{ old('draw_enabled', true) ? 'checked' : '' }} class="rounded">
        <label for="draw_enabled" class="text-sm text-slate-400">{{ __('Draw betting enabled') }}</label>
    </div>

    {{-- CombinedSabong only — which fixed-odds tiers this event offers.
         Hidden/ignored for every other game via odds-fields.js below.
         Leaving every box unchecked falls back to every active tier. --}}
    <div id="odds-tiers-field">
        <label class="block text-sm text-slate-400 mb-1">{{ __('Odds tiers offered (optional — leave all unchecked to offer every active tier)') }}</label>
        <div class="grid grid-cols-3 gap-2">
            @foreach ($oddsTiers as $tier)
                <label class="flex items-center gap-1.5 text-xs bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5">
                    <input type="checkbox" name="odds_tier_ids[]" value="{{ $tier->id }}" {{ in_array($tier->id, old('odds_tier_ids', [])) ? 'checked' : '' }} class="rounded">
                    {{ $tier->label }}
                </label>
            @endforeach
        </div>
        <p class="text-xs text-slate-500 mt-1">
            <a href="{{ route('superadmin.odds-tiers.index') }}" class="underline">{{ __('Manage odds tiers') }}</a>
        </p>
    </div>

    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-6 py-2">{{ __('Create event') }}</button>
</form>

<script>
    (function () {
        const select = document.getElementById('game_id');
        const oddsField = document.getElementById('odds-tiers-field');

        function sync() {
            const opt = select.options[select.selectedIndex];
            oddsField.style.display = opt && opt.dataset.combined === '1' ? '' : 'none';
        }

        select.addEventListener('change', sync);
        sync();
    })();
</script>
@endsection
