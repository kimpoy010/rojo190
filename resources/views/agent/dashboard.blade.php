@extends('layouts.app')

@section('title', __('Agent Dashboard'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('Agent Dashboard') }}</h1>

<div class="grid md:grid-cols-3 gap-4 mb-8">
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs uppercase text-slate-500 mb-1">{{ __('Main balance') }}</p>
        <p class="text-2xl font-bold text-emerald-400">{{ $currencySymbol }}{{ number_format($agent->wallet->main_balance ?? 0, 2) }}</p>
    </div>
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs uppercase text-slate-500 mb-1">{{ __('Commission balance') }}</p>
        <p class="text-2xl font-bold text-amber-400">{{ $currencySymbol }}{{ number_format($agent->wallet->commission_balance ?? 0, 2) }}</p>
        @if (($agent->wallet->commission_balance ?? 0) >= 0.01)
            <form method="POST" action="{{ route('agent.transfer') }}" class="mt-2">
                @csrf
                <button class="text-xs rounded-lg bg-amber-700 hover:bg-amber-600 transition px-3 py-1">{{ __('Transfer to main balance') }}</button>
            </form>
        @endif
    </div>
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs uppercase text-slate-500 mb-1">{{ __('Earned this month') }}</p>
        <p class="text-2xl font-bold">{{ $currencySymbol }}{{ number_format($thisMonth, 2) }}</p>
        <p class="text-xs text-slate-500 mt-1">{{ __('All-time: :amount', ['amount' => $currencySymbol.number_format($totalEarned, 2)]) }}</p>
    </div>
</div>

@if ($agent->agentLevel)
    <p class="text-sm text-slate-400 mb-6">{{ __('Level:') }} <span class="font-semibold text-slate-200">{{ $agent->agentLevel->label }}</span></p>
@else
    <p class="text-sm text-amber-400 mb-6">{{ __('No agent level assigned yet — ask a superadmin to set your level and commission rate.') }}</p>
@endif

@if ($referralUrl)
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-8">
        <h2 class="font-semibold mb-2">{{ __('Your referral link') }}</h2>
        <p class="text-sm text-slate-400 mb-2">{{ __('Share this link to recruit players directly under you.') }}</p>
        <code class="block text-xs bg-slate-800 rounded-lg px-3 py-2 break-all">{{ $referralUrl }}</code>
    </div>
@endif

<div class="grid lg:grid-cols-2 gap-6">
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <h2 class="font-semibold mb-3">{{ __('Downline players (:count)', ['count' => $downlinePlayers->total()]) }}</h2>
        <div class="space-y-1 text-sm max-h-64 overflow-y-auto scroll-thin">
            @forelse ($downlinePlayers as $p)
                <div class="flex justify-between border-b border-slate-800/50 py-1">
                    <span>{{ $p->displayName() }}</span>
                </div>
            @empty
                <p class="text-slate-500">{{ __('No players recruited yet.') }}</p>
            @endforelse
        </div>
        @if ($downlinePlayers->hasPages())
            <div class="mt-2">
                {{ $downlinePlayers->onEachSide(1)->links() }}
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <h2 class="font-semibold mb-3">{{ __('Downline sub-agents (:count)', ['count' => $downlineAgents->count()]) }}</h2>
        <div class="space-y-1 text-sm max-h-64 overflow-y-auto scroll-thin">
            @forelse ($downlineAgents as $a)
                <div class="flex justify-between border-b border-slate-800/50 py-1">
                    <span>{{ $a->displayName() }}</span>
                    <span class="text-slate-500">{{ __(':count downline', ['count' => $a->downline_count]) }}</span>
                </div>
            @empty
                <p class="text-slate-500">{{ __('No sub-agents yet.') }}</p>
            @endforelse
        </div>
    </div>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4 mt-6">
    <h2 class="font-semibold mb-3">{{ __('Recent commission') }}</h2>
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-slate-500 border-b border-slate-800">
                <th class="py-1">{{ __('Player') }}</th>
                <th>{{ __('Side') }}</th>
                <th>{{ __('Bet amount') }}</th>
                <th>{{ __('Commission') }}</th>
                <th>{{ __('Date') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($logs as $log)
                <tr class="border-b border-slate-800/50">
                    <td class="py-1">{{ $log->player?->displayName() ?? '—' }}</td>
                    <td class="capitalize">{{ $log->side }}</td>
                    <td>{{ $currencySymbol }}{{ number_format($log->matched_amount ?? 0, 2) }}</td>
                    <td class="text-amber-400">{{ $currencySymbol }}{{ number_format($log->amount, 2) }}</td>
                    <td class="text-slate-500">{{ $log->credited_at->format('M j, H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-3 text-slate-500">{{ __('No commission earned yet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
