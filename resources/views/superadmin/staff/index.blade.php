@extends('layouts.app')

@section('title', __('Staff'))

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold">{{ __('Staff') }}</h1>
    <a href="{{ route('superadmin.staff.create') }}" class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2 text-sm">+ {{ __('New staff account') }}</a>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-x-auto scroll-thin">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-slate-500 border-b border-slate-800">
                <th class="px-4 py-2">{{ __('Name') }}</th>
                <th>{{ __('Username') }}</th>
                <th>{{ __('Role') }}</th>
                <th>{{ __('Email') }}</th>
                <th class="px-4">{{ __('Status') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($staff as $user)
                <tr class="border-b border-slate-800/50">
                    <td class="px-4 py-2 font-semibold">{{ $user->displayName() }}</td>
                    <td class="text-slate-400">{{ $user->username }}</td>
                    <td>
                        <span class="text-xs px-2 py-0.5 rounded-full {{ $user->hasRole('teller') ? 'bg-emerald-900 text-emerald-300' : 'bg-sky-900 text-sky-300' }}">
                            {{ $user->hasRole('teller') ? __('Teller') : __('Declarator') }}
                        </span>
                    </td>
                    <td class="text-slate-400">{{ $user->email }}</td>
                    <td class="px-4">
                        <span class="text-xs px-2 py-0.5 rounded-full {{ $user->status === 'active' ? 'bg-slate-800 text-slate-300' : 'bg-red-950 text-red-400' }}">
                            {{ $user->status === 'active' ? __('Active') : __('Inactive') }}
                        </span>
                    </td>
                    <td class="px-4 py-2">
                        <div class="flex items-center justify-end gap-3">
                            <a href="{{ route('superadmin.staff.edit', $user) }}" class="text-sm text-red-400 hover:underline">{{ __('Edit') }}</a>
                            <form method="POST" action="{{ route('superadmin.staff.toggle-status', $user) }}"
                                  onsubmit="return confirm('{{ $user->status === 'active' ? __('Deactivate :username? They will be signed out and unable to log back in until reactivated.', ['username' => $user->username]) : __('Reactivate :username?', ['username' => $user->username]) }}');">
                                @csrf
                                <button class="text-sm {{ $user->status === 'active' ? 'text-amber-400' : 'text-emerald-400' }} hover:underline">
                                    {{ $user->status === 'active' ? __('Deactivate') : __('Reactivate') }}
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-slate-500">{{ __('No teller or declarator accounts yet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
