@extends('layouts.app')

@section('title', __('RFID Terminals'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('RFID Terminals') }}</h1>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6 max-w-md">
    <h2 class="font-semibold mb-3">{{ __('New terminal') }}</h2>
    <form method="POST" action="{{ route('superadmin.rfid-terminals.store') }}" class="flex gap-2">
        @csrf
        <input type="text" name="name" required placeholder="{{ __('e.g. Cockpit Window 1') }}"
               class="flex-1 rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
        <button class="rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold px-4 py-2">{{ __('Create') }}</button>
    </form>
</div>

<div class="space-y-4">
    @forelse ($terminals as $terminal)
        <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
            <div class="flex items-center justify-between gap-3 mb-3">
                <p class="font-semibold">
                    {{ $terminal->name }}
                    <span class="text-xs font-normal px-2 py-0.5 rounded-full ml-1 {{ $terminal->is_active ? 'bg-emerald-700 text-emerald-100' : 'bg-slate-700 text-slate-300' }}">
                        {{ $terminal->is_active ? __('ACTIVE') : __('DISABLED') }}
                    </span>
                </p>
                <div class="flex items-center gap-3 text-sm">
                    <form method="POST" action="{{ route('superadmin.rfid-terminals.toggle', $terminal) }}">
                        @csrf
                        <button class="text-slate-400 hover:text-white transition">{{ $terminal->is_active ? __('Disable') : __('Enable') }}</button>
                    </form>
                    <form method="POST" action="{{ route('superadmin.rfid-terminals.destroy', $terminal) }}" onsubmit="return confirm('{{ __('Remove :name? Every reader board at this station will stop working.', ['name' => $terminal->name]) }}');">
                        @csrf
                        @method('DELETE')
                        <button class="text-red-400 hover:underline">{{ __('Remove') }}</button>
                    </form>
                </div>
            </div>

            <dl class="grid sm:grid-cols-2 gap-4 text-sm mb-4">
                <div>
                    <dt class="text-slate-500 mb-1">{{ __('Kiosk URL (self-service screen)') }}</dt>
                    <dd class="font-mono text-xs break-all text-slate-300">{{ route('kiosk.show', $terminal) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 mb-1">{{ __('Balance-check URL (self-service screen)') }}</dt>
                    <dd class="font-mono text-xs break-all text-slate-300">{{ route('kiosk.balance', $terminal) }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500 mb-1">{{ __('Reader board token (TERMINAL_TOKEN in firmware)') }}</dt>
                    <dd class="flex items-center gap-2">
                        <span class="font-mono text-xs break-all text-slate-300">{{ $terminal->token }}</span>
                        <form method="POST" action="{{ route('superadmin.rfid-terminals.regenerate-token', $terminal) }}" onsubmit="return confirm('{{ __('Regenerate the token? Every reader board at this station must be updated to match and re-flashed.') }}');">
                            @csrf
                            <button class="text-xs text-amber-400 hover:underline whitespace-nowrap">{{ __('Regenerate') }}</button>
                        </form>
                    </dd>
                </div>
            </dl>

            <p class="text-sm mb-5">
                <span class="text-slate-400">{{ __('Betting on:') }}</span>
                @if ($activeEvent = $terminal->activeEvent())
                    <span class="text-emerald-400 font-semibold">{{ $activeEvent->name }}</span>
                    <span class="text-xs text-slate-500">— {{ __('follows whichever event is live, no setup needed') }}</span>
                @else
                    <span class="text-slate-500">{{ __('No event is live right now') }}</span>
                @endif
            </p>

            @php
                $roleTheme = ($activeEvent?->game)?->theme() ?? \App\Support\GameTheme::for(null);
            @endphp
            <div class="border-t border-slate-800 pt-4">
                <h3 class="text-sm font-semibold text-slate-300 mb-1">{{ __('Reader boards') }}</h3>
                <p class="text-xs text-slate-500 mb-3">
                    {{ __("Every ESP32 runs identical firmware and auto-registers here the first time it's tapped — it just doesn't work yet until you assign it a role below.") }}
                </p>

                <div class="space-y-2">
                    @forelse ($terminal->readers as $reader)
                        <div class="flex flex-wrap items-center gap-2 bg-slate-800/50 rounded-lg px-3 py-2">
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-xs text-slate-300 truncate">{{ $reader->device_id }}</p>
                                <p class="text-[11px] text-slate-500">
                                    {{ $reader->isAssigned() ? __('Last seen :time', ['time' => $reader->last_seen_at?->diffForHumans()]) : __('Never assigned — inactive') }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('superadmin.rfid-terminals.readers.role', [$terminal, $reader]) }}" class="flex items-center gap-2">
                                @csrf
                                <input type="text" name="label" value="{{ $reader->label }}" placeholder="{{ __('Label (optional)') }}"
                                       class="w-32 rounded-lg bg-slate-800 border border-slate-700 px-2 py-1 text-xs">
                                <select name="role" class="rounded-lg bg-slate-800 border border-slate-700 px-2 py-1 text-xs">
                                    <option value="" {{ ! $reader->role ? 'selected' : '' }}>{{ __('— unassigned —') }}</option>
                                    <option value="meron" {{ $reader->role === 'meron' ? 'selected' : '' }}>{{ strtoupper($activeEvent?->label_meron ?? $roleTheme['meron']['label']) }}</option>
                                    <option value="wala" {{ $reader->role === 'wala' ? 'selected' : '' }}>{{ strtoupper($activeEvent?->label_wala ?? $roleTheme['wala']['label']) }}</option>
                                    <option value="topup" {{ $reader->role === 'topup' ? 'selected' : '' }}>{{ __('TOP-UP') }}</option>
                                    <option value="identify" {{ $reader->role === 'identify' ? 'selected' : '' }}>{{ __('IDENTIFY (teller counter)') }}</option>
                                    <option value="balance" {{ $reader->role === 'balance' ? 'selected' : '' }}>{{ __('BALANCE CHECK (self-service)') }}</option>
                                </select>
                                <button class="rounded-lg bg-emerald-700 hover:bg-emerald-600 transition text-xs px-3 py-1.5">{{ __('Save') }}</button>
                            </form>
                            <form method="POST" action="{{ route('superadmin.rfid-terminals.readers.destroy', [$terminal, $reader]) }}" onsubmit="return confirm('{{ __('Remove this reader?') }}');">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs text-red-400 hover:underline whitespace-nowrap">{{ __('Remove') }}</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __("No reader boards have checked in yet. Power one on and tap any card — it'll appear here.") }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    @empty
        <p class="text-slate-500 text-sm">{{ __('No terminals yet.') }}</p>
    @endforelse
</div>
@endsection
