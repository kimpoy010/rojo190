@extends('layouts.app')

@section('title', __('Payout settings'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('Payout settings') }}</h1>

<form method="POST" action="{{ route('superadmin.settings.update') }}" class="max-w-lg space-y-4">
    @csrf
    @method('PUT')

    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <label class="flex items-center justify-between gap-4 cursor-pointer">
            <span>
                <span class="block font-semibold">{{ __('Balancer switch') }}</span>
                <span class="block text-sm text-slate-400 mt-1">{{ __('When on, caps the combined meron+wala payout ratio so extremely lopsided pools don\'t produce extreme payouts on the favoured side.') }}</span>
            </span>
            <input type="checkbox" name="balancer_switch" value="1" @checked($balancerSwitch === 'on') class="w-5 h-5 rounded shrink-0">
        </label>
    </div>

    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2">{{ __('Save') }}</button>
</form>
@endsection
