@extends('layouts.app')

@section('title', __('Agents'))

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">{{ __('Agents') }}</h1>
    <a href="{{ route('superadmin.agents.create') }}" class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2 text-sm">+ {{ __('New agent') }}</a>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-x-auto scroll-thin">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-slate-500 border-b border-slate-800">
                <th class="px-4 py-2">{{ __('Agent') }}</th>
                <th>{{ __('Upline') }}</th>
                <th>{{ __('Level') }}</th>
                <th>{{ __('Rate (:game)', ['game' => $game?->display_name]) }}</th>
                <th>{{ __('Downline') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($agents as $agent)
                @php $rate = $agent->commissionRates->firstWhere('game_id', $game?->id); @endphp
                <tr class="border-b border-slate-800/50">
                    <td class="px-4 py-2 font-semibold">{{ $agent->displayName() }}</td>
                    <td class="text-slate-400">{{ $agent->agent?->username ?? __('— (top level)') }}</td>
                    <td>
                        <form method="POST" action="{{ route('superadmin.agents.set-level', $agent) }}" class="flex items-center gap-1">
                            @csrf
                            <select name="agent_level_id" onchange="this.form.submit()" class="bg-slate-800 border border-slate-700 rounded px-1 py-0.5 text-xs">
                                <option value="">—</option>
                                @foreach ($agentLevels as $level)
                                    <option value="{{ $level->id }}" @selected($agent->agent_level_id === $level->id)>{{ $level->label }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td>
                        @if ($game)
                            <form method="POST" action="{{ route('superadmin.agents.set-rate', $agent) }}" class="flex items-center gap-1">
                                @csrf
                                <input type="hidden" name="game_id" value="{{ $game->id }}">
                                <input type="number" step="0.01" min="0" max="100" name="commission_rate"
                                       value="{{ $rate?->commission_rate ?? 0 }}"
                                       class="w-16 bg-slate-800 border border-slate-700 rounded px-1 py-0.5 text-xs">
                                <button class="text-xs text-red-400 hover:underline">{{ __('Set') }}</button>
                            </form>
                        @endif
                    </td>
                    <td>{{ $agent->downline_count }}</td>
                    <td class="px-4 text-right text-slate-500 text-xs">{{ $agent->referral_code }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-slate-500">{{ __('No agents yet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
