@extends('layouts.app')

@section('title', __('Cockpits'))

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">{{ __('Cockpits') }}</h1>
    <a href="{{ route('superadmin.cockpit-presets.index') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Manage presets') }}</a>
</div>

<p class="text-sm text-slate-400 mb-6 max-w-lg">
    {{ __('Each ring in the arena has its own camera feed. The declarator picks one of these for every fight before opening it for betting.') }}
</p>

<div class="space-y-4 max-w-lg">
    @foreach ($cockpits as $cockpit)
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <input form="cockpit-{{ $cockpit->id }}-form" name="name" value="{{ old('name', $cockpit->name) }}" required
                       class="font-semibold bg-transparent border-b border-transparent hover:border-slate-700 focus:border-red-500 focus:outline-none px-0 py-1 flex-1">
                <form method="POST" action="{{ route('superadmin.cockpits.destroy', $cockpit) }}" onsubmit="return confirm('{{ __('Remove :name?', ['name' => $cockpit->name]) }}')">
                    @csrf
                    @method('DELETE')
                    <button class="text-xs text-red-400 hover:underline">{{ __('Remove') }}</button>
                </form>
            </div>
            <form method="POST" action="{{ route('superadmin.cockpits.update', $cockpit) }}" id="cockpit-{{ $cockpit->id }}-form" class="space-y-3">
                @csrf
                @method('PUT')
                <div>
                    <label class="block text-sm text-slate-400 mb-1">{{ __('Stream URL (optional)') }}</label>
                    <input name="stream_url" value="{{ old('stream_url', $cockpit->stream_url) }}"
                           class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-sm">
                </div>
                <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-1.5 text-sm">{{ __('Save') }}</button>
            </form>
        </div>
    @endforeach
</div>

<form method="POST" action="{{ route('superadmin.cockpits.store') }}" class="max-w-lg mt-6 flex gap-2">
    @csrf
    <input name="name" required placeholder="{{ __('e.g. Cockpit 4') }}"
           class="flex-1 rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-sm">
    <button class="rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold px-4 py-2 text-sm whitespace-nowrap">{{ __('Add cockpit') }}</button>
</form>
@endsection
