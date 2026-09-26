@extends('layouts.app')

@section('title', __('Events'))

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">{{ __('Events') }}</h1>
    @hasanyrole('declarator|superadmin')
        <a href="{{ route('superadmin.events.create') }}" class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2 text-sm">+ {{ __('New event') }}</a>
    @endhasanyrole
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 divide-y divide-slate-800">
    @forelse ($events as $event)
        <a href="{{ route('declarator.events.show', $event) }}" class="flex items-center justify-between px-4 py-3 hover:bg-slate-800/50 transition">
            <div>
                <p class="font-semibold">{{ $event->name }}</p>
                <p class="text-xs text-slate-500">{{ $event->arena ?? __('Arena TBA') }} &middot; {{ $event->game->display_name ?? '—' }}</p>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full
                {{ $event->status === 'live' ? 'bg-red-600' : ($event->status === 'upcoming' ? 'bg-slate-700' : 'bg-slate-800 text-slate-400') }}">
                {{ strtoupper($event->status) }}
            </span>
        </a>
    @empty
        <p class="px-4 py-6 text-sm text-slate-500">{{ __('No events yet.') }}</p>
    @endforelse
</div>
@endsection
