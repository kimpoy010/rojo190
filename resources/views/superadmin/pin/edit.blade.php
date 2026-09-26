@extends('layouts.app')

@section('title', __('Approval PIN'))

@section('content')
<h1 class="text-2xl font-bold mb-2">{{ __('Approval PIN') }}</h1>
<p class="text-sm text-slate-400 mb-6 max-w-lg">
    {{ __('A short PIN you type in person at a teller counter to approve sensitive actions — right now, voiding a bet ticket. Keep it to yourself; anyone who knows it can approve a void.') }}
</p>

<form method="POST" action="{{ route('superadmin.pin.update') }}" class="max-w-sm space-y-4">
    @csrf
    @method('PUT')

    @if (auth()->user()->hasPin())
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Current PIN') }}</label>
            <input type="password" inputmode="numeric" name="current_pin" required
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
        </div>
    @endif

    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('New PIN (4–6 digits)') }}</label>
        <input type="password" inputmode="numeric" name="pin" required minlength="4" maxlength="6" pattern="\d*"
               class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Confirm new PIN') }}</label>
        <input type="password" inputmode="numeric" name="pin_confirmation" required minlength="4" maxlength="6" pattern="\d*"
               class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>

    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-6 py-2">
        {{ auth()->user()->hasPin() ? __('Update PIN') : __('Set PIN') }}
    </button>
</form>
@endsection
