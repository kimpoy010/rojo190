{{-- Bottom tab bar — the player app is phone-first (see pool-betting.blade.php's
     own full-bleed layout), and the four destinations here cover everything a
     player needs day to day, so they live within thumb's reach at the bottom
     of the screen instead of behind a top-nav dropdown. --}}
@php
    $activeTab = match (true) {
        request()->routeIs('play.wallet.*') => 'wallet',
        request()->routeIs('play.cash.*') => 'cash',
        request()->routeIs('play.profile') => 'profile',
        default => 'home',
    };

    $tabs = [
        ['key' => 'home', 'route' => route('play.index'), 'icon' => 'home', 'label' => __('Home')],
        ['key' => 'wallet', 'route' => route('play.wallet.index'), 'icon' => 'wallet', 'label' => __('Wallet')],
        ['key' => 'cash', 'route' => route('play.cash.index'), 'icon' => 'cash', 'label' => __('Cash')],
        ['key' => 'profile', 'route' => route('play.profile'), 'icon' => 'profile', 'label' => __('Profile')],
    ];
@endphp
<nav class="fixed bottom-0 inset-x-0 z-40 bg-[#140b07]/95 backdrop-blur border-t border-[#1e120c]"
     style="padding-bottom: env(safe-area-inset-bottom, 0px);">
    <div class="grid grid-cols-4 max-w-lg mx-auto">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['route'] }}"
               class="relative flex flex-col items-center gap-0.5 py-2.5 text-[11px] font-semibold transition
                   {{ $activeTab === $tab['key'] ? 'text-red-400' : 'text-[#8a7a70] hover:text-[#c9baaf]' }}"
               @if ($activeTab === $tab['key']) aria-current="page" @endif>
                @if ($activeTab === $tab['key'])
                    <span class="absolute top-0 left-1/2 -translate-x-1/2 w-6 h-0.5 rounded-full bg-red-500 shadow-[0_0_6px_1px_rgba(239,68,68,0.7)]"></span>
                @endif
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    @switch($tab['icon'])
                        @case('home')
                            <path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>
                            @break
                        @case('wallet')
                            <rect x="3" y="7" width="18" height="13" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>
                            @break
                        @case('cash')
                            <rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.4"/>
                            @break
                        @case('profile')
                            <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-7 8-7s8 3 8 7"/>
                            @break
                    @endswitch
                </svg>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
</nav>
