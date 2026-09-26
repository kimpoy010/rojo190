@extends('layouts.app')

@section('title', __('Profile'))

@section('content')
<div class="max-w-sm mx-auto">
    <div class="flex items-center gap-3 mb-4">
        <span class="w-12 h-12 rounded-full bg-slate-700 flex items-center justify-center text-lg font-bold uppercase shrink-0">
            {{ Str::substr($player->displayName(), 0, 1) }}
        </span>
        <div class="min-w-0">
            <h1 class="text-xl font-bold truncate">{{ $player->displayName() }}</h1>
            <p class="text-xs text-slate-500">{{ __('Profile') }}</p>
        </div>
    </div>

    <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 text-center mb-4">
        <h2 class="font-semibold mb-1">{{ __('My QR Card') }}</h2>
        <p class="text-sm text-slate-400 mb-3">{{ __("Show this to a teller to link an RFID card to your account, or to identify yourself at the counter.") }}</p>

        <div class="bg-white rounded-xl p-3 inline-block mb-3">
            {!! \App\Support\QrCodeGenerator::svg(route('teller.rfid.link', $player), 170) !!}
        </div>

        <p class="text-xs text-slate-500 mb-1">{{ __("If a teller can't scan this, they can type in this code instead:") }}</p>
        <p class="text-sm font-mono tracking-widest text-emerald-400">{{ $player->player_code }}</p>
    </div>

    <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 mb-4">
        <h2 class="font-semibold mb-3">{{ __('Language') }}</h2>
        @include('partials.locale-switcher-radio')
    </div>

    <a href="{{ route('account.password.edit') }}" class="block w-full text-center rounded-xl bg-slate-900 border border-slate-800 hover:border-red-600 hover:text-red-400 transition font-semibold py-3 text-sm mb-4">{{ __('Change password') }}</a>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="w-full rounded-xl bg-slate-900 border border-slate-800 hover:border-red-600 hover:text-red-400 transition font-semibold py-3 text-sm">{{ __('Logout') }}</button>
    </form>
</div>
@endsection
