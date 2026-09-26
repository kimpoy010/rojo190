@extends('layouts.app')

@section('title', __('Superadmin'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('Superadmin Dashboard') }}</h1>

<div class="grid md:grid-cols-3 gap-4 mb-8">
    <a href="{{ route('superadmin.events.create') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">＋ {{ __('New event') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('Schedule a new pool-sabong card.') }}</p>
    </a>
    <a href="{{ route('declarator.events.index') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">🐓 {{ __('Run fights') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('Open the declarator console.') }}</p>
    </a>
    <a href="{{ route('superadmin.wallets.index') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">💰 {{ __('Player wallets') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __("Credit or debit a player's balance.") }}</p>
    </a>
    <a href="{{ route('superadmin.agents.index') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">🧑‍💼 {{ __('Agents') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('Manage agent levels and commission rates.') }}</p>
    </a>
    <a href="{{ route('superadmin.staff.index') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">🧑‍✈️ {{ __('Staff') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('Create teller and declarator accounts.') }}</p>
    </a>
    <a href="{{ route('superadmin.audit.index') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">🕵️ {{ __('Audit Trail') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('Every action, by everyone, tamper-evident.') }}</p>
    </a>
    <a href="{{ route('superadmin.rfid-terminals.index') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">📡 {{ __('RFID Terminals') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('Manage self-service betting/top-up kiosks.') }}</p>
    </a>
    <a href="{{ route('superadmin.cockpits.index') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">📹 {{ __('Cockpits') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __("Set each ring's video feed URL.") }}</p>
    </a>
    <a href="{{ route('superadmin.cockpit-presets.index') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">🎛️ {{ __('Cockpit Presets') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('Group cockpits to assign when creating an event.') }}</p>
    </a>
    <a href="{{ route('superadmin.reports.income') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">📊 {{ __('Income report') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('House income per fight — staked vs. paid out.') }}</p>
    </a>
    <a href="{{ route('superadmin.reports.accounting.events') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">📒 {{ __('Betting accounting') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('Every event, fight, and individual bet — the full ledger.') }}</p>
    </a>
    <a href="{{ route('superadmin.reports.teller-cash-flow') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 hover:border-red-600 transition">
        <p class="font-semibold">🎟️ {{ __('Teller cash flow') }}</p>
        <p class="text-sm text-slate-500 mt-1">{{ __('Per-teller ticket sales vs. redemptions.') }}</p>
    </a>
</div>

@if ($game)
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-8">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">{{ __('Pool-Sabong settings') }}</h2>
            <a href="{{ route('superadmin.games.edit', $game) }}" class="text-sm text-red-400 hover:underline">{{ __('Edit') }}</a>
        </div>
        <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div><dt class="text-slate-500">{{ __('Plasada') }}</dt><dd class="font-semibold">{{ $game->plasada }}%</dd></div>
            <div><dt class="text-slate-500">{{ __('Mode') }}</dt><dd class="font-semibold">{{ $game->plasada_mode }}</dd></div>
            <div><dt class="text-slate-500">{{ __('Draw multiplier') }}</dt><dd class="font-semibold">{{ $game->draw_multiplier }}x</dd></div>
            <div><dt class="text-slate-500">{{ __('Max draw bet') }}</dt><dd class="font-semibold">{{ $game->theme()['currency'] }}{{ number_format($game->max_draw_bet, 0) }}</dd></div>
        </dl>
    </div>
@endif

<div class="rounded-xl border border-slate-800 bg-slate-900 divide-y divide-slate-800">
    <div class="px-4 py-2 text-sm text-slate-500">{{ __('Recent events') }}</div>
    @forelse ($events as $event)
        <div class="flex items-center justify-between px-4 py-3 hover:bg-slate-800/50 transition">
            <a href="{{ route('declarator.events.show', $event) }}" class="flex-1">{{ $event->name }}</a>
            <div class="flex items-center gap-3">
                <span class="text-xs px-2 py-0.5 rounded-full {{ $event->status === 'live' ? 'bg-red-600' : 'bg-slate-700' }}">{{ strtoupper($event->status) }}</span>
                <a href="{{ route('superadmin.events.edit', $event) }}" class="text-sm text-red-400 hover:underline">{{ __('Edit') }}</a>
            </div>
        </div>
    @empty
        <p class="px-4 py-6 text-sm text-slate-500">{{ __('No events yet.') }}</p>
    @endforelse
</div>
@endsection
