@extends('layouts.app')

@section('title', __('Log in'))

@section('content')
<div class="max-w-sm mx-auto mt-12 bg-slate-900 border border-slate-800 rounded-xl p-6">
    <h1 class="text-xl font-bold mb-6 text-center">{{ __('Log in') }}</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-700 bg-red-900/40 px-4 py-3 text-red-200 text-sm">
            {{ __($errors->first()) }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Username or email') }}</label>
            <input type="text" name="login" value="{{ old('login') }}" required autofocus
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="block text-sm text-slate-400">{{ __('Password') }}</label>
                <a href="{{ route('password.request') }}" class="text-xs text-red-400 hover:underline">{{ __('Forgot password?') }}</a>
            </div>
            <input type="password" name="password" required
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        @if (config('services.turnstile.site_key'))
            <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}"></div>
        @endif
        <button type="submit" class="w-full rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold py-2">{{ __('Log in') }}</button>
    </form>

    <p class="text-sm text-slate-400 mt-4 text-center">
        {{ __('No account?') }} <a href="{{ route('register') }}" class="text-red-400 hover:underline">{{ __('Register') }}</a>
    </p>

    <div class="mt-6 pt-4 border-t border-slate-800 text-xs text-slate-500 space-y-1">
        <p>{{ __('Demo accounts') }} ({{ __('password') }}: <code>password</code>):</p>
        <p>superadmin@example.com &middot; declarator@example.com &middot; player@example.com</p>
    </div>
</div>

@if (config('services.turnstile.site_key'))
    @push('scripts')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush
@endif
@endsection
