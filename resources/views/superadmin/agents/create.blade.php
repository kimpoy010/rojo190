@extends('layouts.app')

@section('title', __('New agent'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('New Agent') }}</h1>

<form method="POST" action="{{ route('superadmin.agents.store') }}" class="max-w-lg space-y-4">
    @csrf

    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Full name') }}</label>
        <input name="name" value="{{ old('name') }}" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Username') }}</label>
        <input name="username" value="{{ old('username') }}" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Email') }}</label>
        <input type="email" name="email" value="{{ old('email') }}" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Password') }}</label>
        <input type="password" name="password" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Level') }}</label>
        <select name="agent_level_id" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            <option value="">{{ __('— none yet —') }}</option>
            @foreach ($agentLevels as $level)
                <option value="{{ $level->id }}">{{ __(':label (default :rate%)', ['label' => $level->label, 'rate' => $level->commission_rate]) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Upline agent (optional — leave blank for top-level)') }}</label>
        <select name="agent_id" class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            <option value="">{{ __('— top level —') }}</option>
            @foreach ($possibleUplines as $upline)
                <option value="{{ $upline->id }}">{{ $upline->username }}</option>
            @endforeach
        </select>
    </div>

    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-6 py-2">{{ __('Create agent') }}</button>
</form>
@endsection
