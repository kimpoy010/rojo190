@extends('layouts.app')

@section('title', __('Edit game'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('Pool-Sabong settings') }}</h1>

<form method="POST" action="{{ route('superadmin.games.update', $game) }}" enctype="multipart/form-data" class="max-w-lg space-y-4">
    @csrf
    @method('PUT')

    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Display name') }}</label>
        <input name="display_name" value="{{ old('display_name', $game->display_name) }}" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Status') }}</label>
        <select name="game_status" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            <option value="active" @selected($game->game_status === 'active')>{{ __('Active') }}</option>
            <option value="inactive" @selected($game->game_status === 'inactive')>{{ __('Inactive') }}</option>
        </select>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Region (betting labels & colors)') }}</label>
        <select name="region" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            @foreach ($regions as $region)
                <option value="{{ $region }}" @selected(old('region', $game->region) === $region)>{{ __(\App\Support\GameTheme::regionLabel($region)) }}</option>
            @endforeach
        </select>
        <p class="text-xs text-slate-500 mt-1">{{ __('Switching this relabels every event under this game (e.g. Meron/Wala → Rojo/Verde) and swaps the betting colors app-wide.') }}</p>
    </div>
    <div class="flex items-center gap-2">
        <input type="checkbox" name="video_enabled" id="video_enabled" value="1" {{ old('video_enabled', $game->video_enabled) ? 'checked' : '' }} class="rounded">
        <label for="video_enabled" class="text-sm text-slate-400">{{ __('Show live video and the floating "other fights" panel on the betting page') }}</label>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Default banner image (optional)') }}</label>
        @if ($game->default_banner_url)
            <div class="flex items-center gap-3 mb-2">
                <img src="{{ $game->default_banner_url }}" alt="" class="h-24 w-40 object-cover rounded-lg border border-slate-800">
                <label class="flex items-center gap-2 text-sm text-slate-400">
                    <input type="checkbox" name="remove_default_banner" value="1" class="rounded">
                    {{ __('Remove current image') }}
                </label>
            </div>
        @endif
        <input type="file" name="default_banner" accept="image/*" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
        <p class="text-xs text-slate-500 mt-1">{{ __("Used on the player events list card for any event under this game that hasn't had its own thumbnail uploaded.") }}</p>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Plasada (house rake %)') }}</label>
        <input type="number" step="0.01" name="plasada" value="{{ old('plasada', $game->plasada) }}" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Plasada mode') }}</label>
        <select name="plasada_mode" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            <option value="total_pool" @selected($game->plasada_mode === 'total_pool')>{{ __('Total pool') }}</option>
            <option value="losing_side" @selected($game->plasada_mode === 'losing_side')>{{ __('Losing side only') }}</option>
        </select>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Draw multiplier') }}</label>
        <input type="number" step="0.01" name="draw_multiplier" value="{{ old('draw_multiplier', $game->draw_multiplier) }}" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Max draw bet ($)') }}</label>
        <input type="text" inputmode="decimal" name="max_draw_bet" value="{{ old('max_draw_bet', $game->max_draw_bet) }}" class="amount-input w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Min payout warning threshold ($, display only)') }}</label>
        <input type="text" inputmode="decimal" name="min_payout_threshold" value="{{ old('min_payout_threshold', $game->min_payout_threshold) }}" class="amount-input w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>

    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-6 py-2">{{ __('Save') }}</button>
</form>
@endsection
