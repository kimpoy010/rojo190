@extends('layouts.app')

@section('title', __('Start Shift'))

@section('content')
<div class="max-w-sm mx-auto">
    <h1 class="text-2xl font-bold mb-2">{{ __('Start Your Shift') }}</h1>
    <p class="text-sm text-slate-400 mb-6">{{ __("Count the cash in your drawer and enter it below. This is your starting float for the shift's reconciliation.") }}</p>

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <form method="POST" action="{{ route('teller.shift.store') }}" class="space-y-3">
            @csrf
            <div>
                <label class="block text-sm text-slate-400 mb-1">{{ __('Starting cash on hand') }}</label>
                <input type="text" inputmode="decimal" name="starting_cash" required autofocus placeholder="{{ __('e.g. :amount', ['amount' => '5000']) }}"
                       class="amount-input w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-lg">
            </div>
            <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold py-2">{{ __('Start Shift') }}</button>
        </form>
    </div>
</div>
@endsection
