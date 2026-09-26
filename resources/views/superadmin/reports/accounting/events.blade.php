@extends('layouts.app')

@section('title', __('Betting Accounting'))

@section('content')
<div class="flex items-center justify-between mb-6 gap-3 flex-wrap">
    <h1 class="text-2xl font-bold">{{ __('Betting Accounting') }}</h1>
    <div class="flex items-center gap-4">
        <a id="accounting-export" href="{{ route('superadmin.reports.accounting.events.export', request()->query()) }}" class="text-sm text-red-400 hover:underline">{{ __('Export CSV') }}</a>
        <a href="{{ route('superadmin.dashboard') }}" class="text-sm text-slate-400 hover:text-white transition">{{ __('Back to dashboard') }}</a>
    </div>
</div>

<p class="text-sm text-slate-500 mb-6">
    {{ __('Every event, rolled up from its fights and every bet under them. Click an event to see its fights (income report), or a fight there to see every individual bet.') }}
</p>

<form id="accounting-filter-form" method="GET" class="rounded-xl border border-slate-800 bg-slate-900 p-4 mb-6 flex flex-wrap items-end gap-3">
    @include('partials.date-range-field', ['id' => 'accounting-date', 'fromName' => 'from', 'toName' => 'to', 'fromValue' => $filters['from'], 'toValue' => $filters['to']])
    <button class="rounded-lg bg-red-600 hover:bg-red-500 transition font-semibold px-4 py-2 text-sm">{{ __('Filter') }}</button>
    <a id="accounting-clear" href="{{ route('superadmin.reports.accounting.events') }}" @if (! ($filters['from'] || $filters['to'])) hidden @endif class="text-sm text-slate-400 hover:text-white transition px-2 py-2">{{ __('Clear') }}</a>
</form>

<div id="accounting-results">
    @include('superadmin.reports.accounting._events-results')
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        window.initAjaxFilterList({
            form: '#accounting-filter-form',
            results: '#accounting-results',
            clear: '#accounting-clear',
            syncLinks: '#accounting-export',
        });
    });
</script>
@endpush
@endsection
