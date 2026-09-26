@extends('layouts.app')

@section('title', __('Fight #:number Bets', ['number' => $fight->fight_number]))

@section('content')
@php
    $event = $fight->event;
    $sideLabel = fn (string $side) => $event->sideLabel($side);
@endphp

<div class="flex items-center justify-between mb-2 gap-3 flex-wrap">
    <div>
        <h1 class="text-2xl font-bold">{{ __('Fight #:number Bets', ['number' => $fight->fight_number]) }}</h1>
        <p class="text-sm text-slate-500">{{ $event->name }} &middot; {{ __(strtoupper($fight->status)) }}{{ $fight->winner ? ' — '.__(strtoupper($fight->winner)).' '.__('won') : '' }}</p>
    </div>
    <div class="flex items-center gap-4">
        <a href="{{ route('superadmin.reports.accounting.fight.export', $fight) }}" class="text-sm text-red-400 hover:underline">{{ __('Export CSV') }}</a>
        <a href="{{ route('superadmin.reports.income', ['event_id' => $event->id]) }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Back to fights') }}</a>
    </div>
</div>

<div class="grid grid-cols-3 gap-4 my-6">
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs text-slate-500 mb-1 uppercase tracking-wide">{{ __('Bets') }}</p>
        <p class="text-xl font-extrabold">{{ number_format($summary->bet_count) }}</p>
    </div>
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs text-slate-500 mb-1 uppercase tracking-wide">{{ __('Staked') }}</p>
        <p class="text-xl font-extrabold">{{ $currencySymbol }}{{ number_format($summary->staked, 2) }}</p>
    </div>
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs text-slate-500 mb-1 uppercase tracking-wide">{{ __('Net income') }}</p>
        @php $netTotal = $summary->staked - $summary->paid_out; @endphp
        <p class="text-xl font-extrabold {{ $netTotal >= 0 ? 'text-emerald-400' : 'text-red-400' }}">{{ $currencySymbol }}{{ number_format($netTotal, 2) }}</p>
    </div>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-x-auto scroll-thin">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-slate-500 uppercase tracking-wide border-b border-slate-800">
                <th class="px-4 py-3 font-medium">{{ __('Bettor') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Side') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Amount') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Payout') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Placed') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/60">
            @forelse ($bets as $bet)
                <tr class="hover:bg-slate-800/40 transition">
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if ($bet->isCounterBet())
                            <span class="text-slate-200">{{ __('Ticket :code', ['code' => $bet->ticket_code]) }}</span>
                            @if ($bet->placedByTeller)
                                <span class="block text-[11px] text-slate-500">{{ __('by :name', ['name' => $bet->placedByTeller->displayName()]) }}</span>
                            @endif
                        @else
                            {{ $bet->user?->displayName() ?? '—' }}
                        @endif
                    </td>
                    <td class="px-4 py-3 uppercase">{{ $sideLabel($bet->side) }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">{{ $currencySymbol }}{{ number_format($bet->amount, 2) }}</td>
                    <td class="px-4 py-3">
                        @php
                            $badge = match ($bet->status) {
                                'settled' => 'bg-emerald-700 text-emerald-100',
                                'voided' => 'bg-slate-700 text-slate-300',
                                'refunded' => 'bg-amber-700 text-amber-100',
                                default => 'bg-sky-700 text-sky-100',
                            };
                        @endphp
                        <span class="text-xs px-2 py-0.5 rounded-full {{ $badge }} uppercase">{{ $bet->status }}</span>
                        @if ($bet->isCounterBet() && $bet->status === 'settled' && (float) $bet->payout > 0)
                            <span class="block text-[11px] text-slate-500 mt-0.5">
                                {{ $bet->redeemed_at ? __('redeemed :time', ['time' => $bet->redeemed_at->format('M j, g:i A')]) : __('not yet redeemed') }}
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap {{ (float) $bet->payout > 0 ? 'text-emerald-400 font-semibold' : '' }}">
                        {{ $bet->payout !== null ? $currencySymbol.number_format($bet->payout, 2) : '—' }}
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-slate-500">{{ $bet->created_at->format('M j, g:i A') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-slate-500">{{ __('No bets on this fight.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($bets->hasPages())
        <div class="p-4 border-t border-slate-800">
            {{ $bets->links() }}
        </div>
    @endif
</div>
@endsection
