@extends('layouts.app')

@section('title', __('Shift History'))

@php
    $varianceStatus = function ($shift) {
        if ($shift->isOpen()) return __('OPEN');
        $variance = (float) ($shift->variance ?? 0);
        if (abs($variance) < 0.01) return __('BALANCED');
        return $variance > 0 ? __('OVER') : __('SHORT');
    };
@endphp

@section('content')
<div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
    <h1 class="text-2xl font-bold">{{ __('Shift History') }}</h1>
    <a href="{{ route('teller.dashboard') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Back to dashboard') }}</a>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
    <div class="divide-y divide-slate-800/60">
        @forelse ($shifts as $shift)
            @php
                $variance = (float) ($shift->variance ?? 0);
                $isBalanced = abs($variance) < 0.01;
            @endphp
            <a href="{{ route('teller.shift.report', $shift) }}" class="block px-1 py-3 hover:bg-slate-800/40 rounded-lg transition">
                <div class="flex items-center justify-between text-xs text-slate-500">
                    <span>{{ $shift->started_at->format('M j, Y') }}</span>
                    <span>{{ $shift->started_at->format('g:i A') }} &rarr; {{ $shift->ended_at?->format('g:i A') ?? __('open') }}</span>
                </div>
                <div class="flex items-center justify-between mt-1 gap-3">
                    <span class="font-semibold text-slate-100">
                        {{ __('Starting :amount', ['amount' => $currencySymbol.number_format($shift->starting_cash, 2)]) }}
                        <span class="text-xs font-normal px-2 py-0.5 rounded-full ml-1
                            {{ $shift->isOpen() ? 'bg-sky-700 text-sky-100' : ($isBalanced ? 'bg-emerald-700 text-emerald-100' : 'bg-red-800 text-red-100') }}">
                            {{ $varianceStatus($shift) }}
                        </span>
                    </span>
                    <span class="font-semibold whitespace-nowrap text-slate-300">
                        {{ $shift->isOpen() ? '—' : $currencySymbol.number_format($shift->ending_cash, 2) }}
                    </span>
                </div>
            </a>
        @empty
            <p class="py-4 px-1 text-slate-500 text-sm">{{ __('No shifts yet.') }}</p>
        @endforelse
    </div>

    @if ($shifts->hasPages())
        <div class="mt-4">
            {{ $shifts->links() }}
        </div>
    @endif
</div>
@endsection
