@extends('layouts.app')

@section('title', __('Register'))

@section('content')
<div class="max-w-sm mx-auto mt-12 bg-slate-900 border border-slate-800 rounded-xl p-6">
    <h1 class="text-xl font-bold mb-6 text-center">{{ __('Create your account') }}</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-700 bg-red-900/40 px-4 py-3 text-red-200 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ __($error) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($refCode && $refRole === 'agent')
        <div class="mb-4 rounded-lg border border-emerald-700 bg-emerald-900/40 px-4 py-3 text-emerald-200 text-sm">
            {{ __("You're joining as a player under an agent's referral.") }}
        </div>
    @elseif ($refCode && $refRole === 'superadmin')
        <div class="mb-4 rounded-lg border border-sky-700 bg-sky-900/40 px-4 py-3 text-sky-200 text-sm">
            {{ __("You're registering as a new agent.") }}
        </div>
    @elseif ($refCode)
        <div class="mb-4 rounded-lg border border-amber-700 bg-amber-900/40 px-4 py-3 text-amber-200 text-sm">
            {{ __("That referral link isn't recognized — registering as a regular player instead.") }}
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="ref_code" value="{{ $refCode }}">
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Full name') }}</label>
            <input type="text" name="name" value="{{ old('name') }}" required
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Username') }}</label>
            <input type="text" name="username" value="{{ old('username') }}" required
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Email') }}</label>
            <input type="email" name="email" value="{{ old('email') }}" required
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Password') }}</label>
            <input type="password" name="password" required
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Confirm password') }}</label>
            <input type="password" name="password_confirmation" required
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        @if (config('services.turnstile.site_key'))
            <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
        @endif
        <button type="submit" class="w-full rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold py-2">{{ __('Register') }}</button>
    </form>

    <p class="text-sm text-slate-400 mt-4 text-center">
        {{ __('Already have an account?') }} <a href="{{ route('login') }}" class="text-red-400 hover:underline">{{ __('Log in') }}</a>
    </p>
</div>

@if (config('services.turnstile.site_key'))
    @push('scripts')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif
@endsection
