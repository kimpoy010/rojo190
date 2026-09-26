<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full antialiased @role('player') bg-[#0b0705] text-[#f5efe9] @else bg-slate-950 text-slate-100 @endrole">
    {{-- A player gets no top nav at all — logo/locale-switcher/logout all
         moved to the Profile tab (see partials.player-bottom-tabs and
         player/profile/show.blade.php) so the bottom tab bar is the only
         chrome on screen, leaving more room for the actual app content on
         a phone. Every other role keeps this bar as-is. --}}
    @unless (auth()->check() && auth()->user()->hasRole('player'))
        <nav id="site-nav" class="border-b border-slate-800 bg-slate-900/80 backdrop-blur sticky top-0 z-40">
            <div class="max-w-6xl mx-auto px-3 sm:px-4 py-2.5 sm:py-3 flex items-center justify-between gap-2">
                <a href="{{ route(auth()->check() ? auth()->user()->homeRouteName() : 'login') }}" class="font-bold text-base sm:text-lg tracking-tight text-red-400 whitespace-nowrap shrink-0">🐓 {{ __('Pool Sabong') }}</a>

                @guest
                    @include('partials.locale-switcher')
                @endguest

                @auth
                    <div class="flex items-center gap-1.5 sm:gap-4 text-sm min-w-0">
                        @include('partials.locale-switcher')
                        @role('agent')
                            {{-- Hidden below sm: with the locale switcher + avatar also competing
                                 for the same row, two currency figures don't fit next to them on a
                                 phone — and the agent dashboard shows both balances prominently on
                                 the page itself, so nothing is lost by hiding them here. --}}
                            <span class="hidden sm:inline text-emerald-400 font-semibold text-sm whitespace-nowrap" title="{{ __('Main balance') }}">{{ $currencySymbol ?? '$' }}<span data-wallet-balance="main">{{ number_format(auth()->user()->wallet->main_balance ?? 0, 2) }}</span></span>
                            <span class="hidden sm:inline text-amber-400 font-semibold text-sm whitespace-nowrap" title="{{ __('Commission balance') }}">💰{{ $currencySymbol ?? '$' }}<span data-wallet-balance="commission">{{ number_format(auth()->user()->wallet->commission_balance ?? 0, 2) }}</span></span>
                        @endrole

                        <div id="user-menu" class="relative shrink-0">
                            <button type="button" id="user-menu-btn" class="flex items-center gap-1.5 text-slate-300 hover:text-white transition" aria-haspopup="true" aria-expanded="false">
                                <span class="w-6 h-6 rounded-full bg-slate-700 flex items-center justify-center text-[11px] font-bold uppercase shrink-0">
                                    {{ Str::substr(auth()->user()->displayName(), 0, 1) }}
                                </span>
                                <span class="hidden sm:inline">{{ auth()->user()->displayName() }}</span>
                                <svg id="user-menu-caret" class="w-3 h-3 text-slate-500 transition-transform" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <div id="user-menu-dropdown" class="hidden absolute right-0 mt-2 w-56 rounded-lg border border-slate-800 bg-slate-900 shadow-lg py-1 z-50">
                                @role('agent')
                                    <a href="{{ route('agent.dashboard') }}" class="block px-4 py-2 whitespace-nowrap text-slate-300 hover:bg-slate-800 hover:text-white transition">{{ __('My Dashboard') }}</a>
                                @endrole
                                @role('teller')
                                    <a href="{{ route('teller.dashboard') }}" class="block px-4 py-2 whitespace-nowrap text-slate-300 hover:bg-slate-800 hover:text-white transition">{{ __('Teller Dashboard') }}</a>
                                @endrole
                                @role('superadmin')
                                    <a href="{{ route('superadmin.pin.edit') }}" class="block px-4 py-2 whitespace-nowrap text-slate-300 hover:bg-slate-800 hover:text-white transition">{{ __('Approval PIN') }}</a>
                                    <a href="{{ route('superadmin.settings.edit') }}" class="block px-4 py-2 whitespace-nowrap text-slate-300 hover:bg-slate-800 hover:text-white transition">{{ __('Payout settings') }}</a>
                                @endrole
                                <a href="{{ route('account.password.edit') }}" class="block px-4 py-2 whitespace-nowrap text-slate-300 hover:bg-slate-800 hover:text-white transition">{{ __('Change password') }}</a>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button class="w-full text-left px-4 py-2 whitespace-nowrap text-slate-300 hover:bg-slate-800 hover:text-red-400 transition">{{ __('Logout') }}</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endauth
            </div>
        </nav>
    @endunless

    {{-- Extra bottom padding for a player, whose bottom tab bar (below) would
         otherwise sit on top of the page's own last bit of content — the
         safe-area inset is on top of the tab bar's own height, not instead
         of it, since the tab bar already pads itself for the home indicator. --}}
    <main class="max-w-6xl mx-auto px-4 py-6 @role('player') pb-24 @endrole">
        @if (session('success'))
            <div class="mb-4 rounded-lg border border-emerald-700 bg-emerald-900/40 px-4 py-3 text-emerald-200 text-sm">{{ __(session('success')) }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-lg border border-red-700 bg-red-900/40 px-4 py-3 text-red-200 text-sm">{{ __(session('error')) }}</div>
        @endif
        @if (session('info'))
            <div class="mb-4 rounded-lg border border-sky-700 bg-sky-900/40 px-4 py-3 text-sky-200 text-sm">{{ __(session('info')) }}</div>
        @endif

        @yield('content')
    </main>

    @role('player')
        @include('partials.player-bottom-tabs')
    @endrole

    <script>
        (function () {
            const btn = document.getElementById('user-menu-btn');
            const dropdown = document.getElementById('user-menu-dropdown');
            const caret = document.getElementById('user-menu-caret');
            if (!btn || !dropdown) return;

            const close = () => {
                dropdown.classList.add('hidden');
                btn.setAttribute('aria-expanded', 'false');
                caret.classList.remove('rotate-180');
            };

            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = !dropdown.classList.contains('hidden');
                isOpen ? close() : (dropdown.classList.remove('hidden'), btn.setAttribute('aria-expanded', 'true'), caret.classList.add('rotate-180'));
            });

            document.addEventListener('click', (e) => {
                if (!document.getElementById('user-menu').contains(e.target)) close();
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') close();
            });
        })();
    </script>

    @auth
        <script>
            // Any wallet balance shown anywhere (nav, wallet page, the
            // betting page's top bar) updates live off one private
            // per-user channel — a bet placed, a payout on a win, a
            // teller cash approval, all land here without a page reload.
            (function () {
                function apply(field, value) {
                    const formatted = Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    document.querySelectorAll('[data-wallet-balance="' + field + '"]').forEach((el) => {
                        el.textContent = formatted;
                    });
                }

                window.addEventListener('echo:ready', () => {
                    window.Echo.private('wallet.{{ auth()->id() }}')
                        .listen('.WalletBalanceUpdated', (e) => {
                            apply('main', e.main_balance);
                            apply('commission', e.commission_balance);
                        });
                });
            })();
        </script>
    @endauth

    @stack('scripts')
</body>
</html>
