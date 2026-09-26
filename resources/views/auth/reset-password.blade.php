@extends('layouts.app')

@section('title', __('Reset password'))

@section('content')
<div class="max-w-sm mx-auto mt-12 bg-slate-900 border border-slate-800 rounded-xl p-6">
    <h1 class="text-xl font-bold mb-6 text-center">{{ __('Reset your password') }}</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-700 bg-red-900/40 px-4 py-3 text-red-200 text-sm">
            {{ __($errors->first()) }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Email') }}</label>
            <input type="email" name="email" value="{{ old('email', $email) }}" required autofocus
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('New password') }}</label>
            <input type="password" name="password" required
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Confirm password') }}</label>
            <input type="password" name="password_confirmation" required
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <button type="submit" class="w-full rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold py-2">{{ __('Reset password') }}</button>
    </form>
</div>
@endsection
