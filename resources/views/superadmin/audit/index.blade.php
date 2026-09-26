@extends('layouts.app')

@section('title', __('Audit Trail'))

@section('content')
<div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
    <h1 class="text-2xl font-bold">{{ __('Audit Trail') }}</h1>
    <a href="{{ route('superadmin.dashboard') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Back to dashboard') }}</a>
</div>

<form id="audit-filter-form" method="GET" action="{{ route('superadmin.audit.index') }}" class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs text-slate-500 mb-1">{{ __('Username') }}</label>
        <input type="text" name="username" value="{{ $username }}" placeholder="{{ __('Search by name, username or email…') }}"
               class="rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-sm w-56">
    </div>
    <div>
        <label class="block text-xs text-slate-500 mb-1">{{ __('Action') }}</label>
        <select name="action" class="rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-sm">
            <option value="">{{ __('All actions') }}</option>
            @foreach ($actions as $actionOption)
                <option value="{{ $actionOption }}" @selected($action === $actionOption)>{{ $actionOption }}</option>
            @endforeach
        </select>
    </div>
    @include('partials.date-range-field', ['id' => 'audit-date', 'fromName' => 'date_from', 'toName' => 'date_to', 'fromValue' => $dateFrom, 'toValue' => $dateTo])
    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2 text-sm">{{ __('Filter') }}</button>
    <a id="audit-clear" href="{{ route('superadmin.audit.index') }}" @if (! ($username || $action || $dateFrom || $dateTo)) hidden @endif class="text-sm text-slate-400 hover:text-white transition px-2 py-2">{{ __('Clear') }}</a>
</form>

<div id="audit-results">
    @include('superadmin.audit._results')
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        window.initAjaxFilterList({ form: '#audit-filter-form', results: '#audit-results', clear: '#audit-clear' });
    });
</script>
@endpush
@endsection
