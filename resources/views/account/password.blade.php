@extends('layouts.app')

@section('title', __('Change password'))

@section('content')
<div class="max-w-sm mx-auto">
    <h1 class="text-xl font-bold mb-6">{{ __('Change password') }}</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-700 bg-red-900/40 px-4 py-3 text-red-200 text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ __($error) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('account.password.update') }}" class="space-y-4 bg-slate-900 border border-slate-800 rounded-xl p-4">
        @csrf
        @method('PUT')
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('New password') }}</label>
            <input type="password" name="password" required autocomplete="new-password"
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <div>
            <label class="block text-sm text-slate-400 mb-1">{{ __('Confirm new password') }}</label>
            <input type="password" name="password_confirmation" required autocomplete="new-password"
                   class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
        </div>
        <button type="submit" class="w-full rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold py-2">{{ __('Update password') }}</button>
    </form>
</div>
@endsection
