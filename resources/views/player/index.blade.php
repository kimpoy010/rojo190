@extends('layouts.app')

@section('title', __('Live Events'))

@php
    // Same rgba-from-hex trick as pool-betting.blade.php — Tailwind's
    // build-time scanner can't compile a runtime-interpolated arbitrary
    // class, so the two comet colors below go in as plain inline rgba().
    $hexToRgb = fn (string $hex) => implode(',', sscanf($hex, '#%02x%02x%02x'));
    $meronRgb = $hexToRgb($theme['meron']['hex']);
    $walaRgb = $hexToRgb($theme['wala']['hex']);
@endphp
@section('content')
<style>
    /* Static neon border on the live/ongoing tiles, alternating between
       the region's two side colors tile-by-tile (Meron's — always red —
       on odd tiles, Wala's — blue for Philippines, green for Mexico — on
       even ones) rather than the region colors chasing each other as an
       animated comet. */
    .live-tile-neon {
        border-width: 2px;
        border-style: solid;
    }
    .live-tile-neon:nth-child(odd) {
        border-color: rgb({{ $meronRgb }});
        box-shadow: 0 0 14px 1px rgba({{ $meronRgb }}, 0.55), 0 0 30px 4px rgba({{ $meronRgb }}, 0.25);
    }
    .live-tile-neon:nth-child(even) {
        border-color: rgb({{ $walaRgb }});
        box-shadow: 0 0 14px 1px rgba({{ $walaRgb }}, 0.55), 0 0 30px 4px rgba({{ $walaRgb }}, 0.25);
    }
</style>
<p class="text-[10.5px] font-extrabold tracking-[0.16em] text-[#e0793a] mb-1">{{ __('POOL SABONG') }}</p>
<h1 class="text-2xl font-extrabold tracking-tight mb-1">{{ __('Live Events') }}</h1>
<p class="text-sm text-[#8a7a70] mb-6">{{ __('Pick a live event to start betting') }}</p>

<div class="mb-10">
    <h2 class="flex items-center gap-2 text-[11.5px] font-extrabold uppercase tracking-[0.1em] text-red-400 mb-3">
        <span class="relative flex h-[7px] w-[7px]">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75"></span>
            <span class="relative inline-flex h-[7px] w-[7px] rounded-full bg-red-500 shadow-[0_0_8px_2px_rgba(239,68,68,0.55)]"></span>
        </span>
        {{ __('Live now') }}
    </h2>
    @if ($liveEvents->isEmpty())
        <p class="text-[#8a7a70] text-sm">{{ __('No live events right now. Check back soon.') }}</p>
    @else
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
            @foreach ($liveEvents as $event)
                <div class="group relative block overflow-hidden rounded-xl bg-[#160e0a] transition live-tile-neon">
                    <div class="relative aspect-video bg-black">
                        @if ($event->displayBannerUrl())
                            <img src="{{ $event->displayBannerUrl() }}" alt="" class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-105">
                        @else
                            <div class="absolute inset-0" style="background:radial-gradient(circle at 50% 40%,#5a1a12,#1c0805);"></div>
                        @endif
                        <span class="absolute top-2 left-2 flex items-center gap-1 rounded-full bg-red-600 px-2 py-0.5 text-[10px] sm:text-[11px] font-bold uppercase text-white shadow-[0_0_10px_1px_rgba(239,68,68,0.6)]">
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-white"></span>
                            {{ __('LIVE') }}
                        </span>
                    </div>
                    <div class="p-2.5 sm:p-4">
                        <p class="font-semibold text-sm sm:text-base truncate">{{ $event->name }}</p>
                        <p class="text-xs sm:text-sm text-[#8a7a70] truncate">{{ $event->arena ?? __('Arena TBA') }}</p>
                    </div>
                    <a href="{{ route('play.events.enter', $event) }}" class="absolute inset-0" aria-label="{{ $event->name }}"></a>
                </div>
            @endforeach
        </div>
    @endif
</div>

@if ($ongoingFights->isNotEmpty())
    <div class="mb-10">
        <h2 class="flex items-center gap-2 text-[11.5px] font-extrabold uppercase tracking-[0.1em] text-red-400 mb-3">
            <span class="relative flex h-[7px] w-[7px]">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-500 opacity-75"></span>
                <span class="relative inline-flex h-[7px] w-[7px] rounded-full bg-red-500 shadow-[0_0_8px_2px_rgba(239,68,68,0.55)]"></span>
            </span>
            {{ __('Ongoing fights') }}
        </h2>
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
            @foreach ($ongoingFights as $item)
                <a href="{{ route('play.pool-fight', $item['fight']) }}" class="group relative block overflow-hidden rounded-xl bg-[#160e0a] transition live-tile-neon">
                    <div class="relative aspect-video bg-black">
                        @if ($item['event']->displayBannerUrl())
                            <img src="{{ $item['event']->displayBannerUrl() }}" alt="" class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-105">
                        @else
                            <div class="absolute inset-0" style="background:radial-gradient(circle at 50% 40%,#5a1a12,#1c0805);"></div>
                        @endif
                        <span class="absolute top-2 left-2 flex items-center gap-1 rounded-full bg-red-600 px-2 py-0.5 text-[10px] sm:text-[11px] font-bold uppercase text-white shadow-[0_0_10px_1px_rgba(239,68,68,0.6)]">
                            <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-white"></span>
                            {{ __('LIVE') }}
                        </span>
                    </div>
                    <div class="p-2.5 sm:p-4">
                        <p class="font-semibold text-sm sm:text-base truncate">{{ $item['event']->name }}</p>
                        <p class="text-xs sm:text-sm text-[#8a7a70] truncate">{{ __('Fight #:number', ['number' => $item['fight']->fight_number]) }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endif

<div>
    <h2 class="flex items-center gap-2 text-[11.5px] font-extrabold uppercase tracking-[0.1em] text-[#8a7a70] mb-3">
        <span class="h-[7px] w-[7px] rounded-full bg-[#4a3a30]"></span>
        {{ __('Upcoming') }}
    </h2>
    @if ($upcomingEvents->isEmpty())
        <p class="text-[#8a7a70] text-sm">{{ __('No upcoming events scheduled.') }}</p>
    @else
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
            @foreach ($upcomingEvents as $event)
                <div class="overflow-hidden rounded-xl bg-[#160e0a] border border-[#2a1a14]">
                    <div class="relative aspect-video bg-[#1c130e]">
                        @if ($event->displayBannerUrl())
                            <img src="{{ $event->displayBannerUrl() }}" alt="" class="absolute inset-0 h-full w-full object-cover grayscale">
                        @endif
                        <span class="absolute top-2 left-2 rounded-full bg-[#2a1a14] px-2 py-0.5 text-[10px] sm:text-[11px] font-bold uppercase text-[#c9baaf]">
                            {{ __('Upcoming') }}
                        </span>
                    </div>
                    <div class="p-2.5 sm:p-4">
                        <p class="font-semibold text-sm sm:text-base truncate">{{ $event->name }}</p>
                        <p class="text-xs sm:text-sm text-[#8a7a70] truncate">{{ $event->arena ?? __('Arena TBA') }}</p>
                        @if ($event->date)
                            <p class="text-xs text-[#6b5c53] mt-1">{{ $event->date->format('M j, g:i A') }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
