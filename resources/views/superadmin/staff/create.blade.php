@extends('layouts.app')

@section('title', __('New staff account'))

@section('content')
<h1 class="text-2xl font-bold mb-6">{{ __('New Staff Account') }}</h1>

<form method="POST" action="{{ route('superadmin.staff.store') }}" class="max-w-lg space-y-4">
    @csrf

    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Role') }}</label>
        <select name="role" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
            <option value="">{{ __('— select —') }}</option>
            <option value="teller" @selected(old('role') === 'teller')>{{ __('Teller') }}</option>
            <option value="declarator" @selected(old('role') === 'declarator')>{{ __('Declarator') }}</option>
        </select>
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Full name') }}</label>
        <input name="name" value="{{ old('name') }}" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Username') }}</label>
        <input name="username" value="{{ old('username') }}" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Email') }}</label>
        <input type="email" name="email" value="{{ old('email') }}" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>
    <div>
        <label class="block text-sm text-slate-400 mb-1">{{ __('Password') }}</label>
        <input type="password" name="password" required class="w-full rounded-lg bg-slate-800 border border-slate-700 px-3 py-2">
    </div>

    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-6 py-2">{{ __('Create account') }}</button>
</form>
@endsection
