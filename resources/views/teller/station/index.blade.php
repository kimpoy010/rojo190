@extends('layouts.app')

@section('title', __('Teller Stations'))

@section('content')
<div class="max-w-md mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">{{ __('Teller Stations') }}</h1>
        <a href="{{ route('teller.dashboard') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Back to dashboard') }}</a>
    </div>

    <p class="text-sm text-slate-400 mb-4">{{ __('Pick the station whose reader is at your counter.') }}</p>

    <div class="rounded-xl border border-slate-800 bg-slate-900 divide-y divide-slate-800">
        @forelse ($terminals as $terminal)
            <a href="{{ route('teller.station.show', $terminal) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-800/50 transition">
                <span class="font-semibold">{{ $terminal->name }}</span>
                <span class="text-slate-500">&rarr;</span>
            </a>
        @empty
            <p class="px-4 py-6 text-sm text-slate-500">{{ __('No terminals yet — ask a superadmin to create one under RFID Terminals.') }}</p>
        @endforelse
    </div>
</div>
@endsection
