@extends('layouts.app')

@section('title', __('Ticket Receipt'))

@section('content')
<div class="max-w-sm mx-auto text-center">
    <p class="text-emerald-400 font-semibold mb-1">{{ __('Ticket written') }}</p>
    <p class="text-xs text-slate-500 mb-4">{{ __("Print this and hand it to the bettor — it's their only proof of the bet.") }}</p>

    @include('teller.tickets._printable', ['bet' => $bet])

    <div class="grid grid-cols-2 gap-2 mt-4">
        <button type="button" onclick="window.print()" class="rounded-lg bg-slate-700 hover:bg-slate-600 transition font-semibold px-4 py-2">🖨️ {{ __('Print again') }}</button>
        <a href="{{ route('teller.tickets.create') }}" class="rounded-lg bg-emerald-600 hover:bg-emerald-500 transition font-semibold px-4 py-2">{{ __('Write another') }}</a>
    </div>
</div>

@push('scripts')
<script>
(function () {
    // Fast-paced arena floor: the teller shouldn't have to click a "Print"
    // button for every single ticket. Fire the print dialog automatically
    // the moment this page loads — a short delay lets the QR SVG finish
    // painting first. The "Print again" button above stays as a manual
    // fallback if the dialog gets dismissed or the printer misfires.
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 150);
    });
})();
</script>
@endpush
@endsection
