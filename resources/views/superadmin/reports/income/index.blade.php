@extends('layouts.app')

@section('title', __('Income Report'))

@section('content')
<div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
    <h1 class="text-2xl font-bold">{{ __('Income Report') }}</h1>
    <div class="flex items-center gap-4">
        <a id="income-export" href="{{ route('superadmin.reports.income.export', request()->query()) }}" class="text-sm text-red-400 hover:underline">{{ __('Export CSV') }}</a>
        <a href="{{ route('superadmin.dashboard') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Back to dashboard') }}</a>
    </div>
</div>

<form id="income-filter-form" method="GET" class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6 flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs text-slate-500 mb-1">{{ __('Event') }}</label>
        <select name="event_id" class="rounded-lg bg-slate-800 border border-slate-700 px-3 py-2 text-sm">
            <option value="">{{ __('All events') }}</option>
            @foreach ($events as $event)
                <option value="{{ $event->id }}" @selected($filters['event_id'] == $event->id)>{{ $event->name }}</option>
            @endforeach
        </select>
    </div>
    @include('partials.date-range-field', ['id' => 'income-date', 'fromName' => 'from', 'toName' => 'to', 'fromValue' => $filters['from'], 'toValue' => $filters['to']])
    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2 text-sm">{{ __('Filter') }}</button>
    <a id="income-clear" href="{{ route('superadmin.reports.income') }}" @if (! ($filters['event_id'] || $filters['from'] || $filters['to'])) hidden @endif class="text-sm text-slate-400 hover:text-white transition px-2 py-2">{{ __('Clear') }}</a>
</form>

<div id="income-results">
    @include('superadmin.reports.income._results')
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        window.initAjaxFilterList({
            form: '#income-filter-form',
            results: '#income-results',
            clear: '#income-clear',
            syncLinks: '#income-export',
        });
    });
</script>
@endpush
@endsection
