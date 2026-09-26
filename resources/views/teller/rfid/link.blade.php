@extends('layouts.app')

@section('title', __('Link Card — :name', ['name' => $player->displayName()]))

@section('content')
<div class="max-w-sm mx-auto">
    <h1 class="text-2xl font-bold mb-6">{{ __('Link RFID Card') }}</h1>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6">
        <p class="text-xs uppercase text-slate-500 mb-1">{{ __('Player') }}</p>
        <p class="font-bold text-lg">{{ $player->displayName() }}</p>
        @if ($player->name && $player->name !== $player->displayName())
            <p class="text-sm text-slate-400">{{ $player->name }}</p>
        @endif

        @if ($player->rfid_uid)
            <div class="mt-3 rounded-lg border border-amber-700 bg-amber-900/30 px-3 py-2 text-sm text-amber-200">
                {{ __('This player already has a card linked (:uid). Linking a new one will replace it.', ['uid' => $player->rfid_uid]) }}
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <form method="POST" action="{{ route('teller.rfid.store') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="player_code" value="{{ $player->player_code }}">
            <div>
                <label class="block text-sm text-slate-400 mb-1">{{ __('Tag ID') }}</label>
                <input type="text" name="tag_uid" required autofocus value="{{ old('tag_uid') }}"
                       class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 font-mono">
            </div>
            <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold py-2">
                {{ $player->rfid_uid ? __('Replace Card') : __('Link Card') }}
            </button>
            <a href="{{ route('teller.rfid.index') }}" class="block text-center text-sm text-slate-400 hover:text-white transition">{{ __('Cancel') }}</a>
        </form>
    </div>
</div>
@endsection
