{{-- Full-word, radio-row language switcher for a page that gives it its own
     dedicated section (unlike partials.locale-switcher's compact EN/ES pill,
     built for squeezing into the top nav's single row). Navigates on tap
     like the rest of the app's own links — no form/JS needed, so this is
     styled to look like a radio group without actually being one. --}}
@php
    $locales = [
        'en' => __('English'),
        'es' => __('Español'),
    ];
@endphp
<div class="space-y-2">
    @foreach ($locales as $code => $label)
        @php $active = app()->getLocale() === $code; @endphp
        <a href="{{ route('locale.switch', $code) }}"
           class="flex items-center gap-3 rounded-lg border px-4 py-3 transition
               {{ $active ? 'border-red-500 bg-red-500/10' : 'border-slate-700 hover:border-slate-600' }}"
           @if ($active) aria-current="true" @endif>
            <span class="w-4 h-4 rounded-full border-2 flex items-center justify-center shrink-0 {{ $active ? 'border-red-500' : 'border-slate-600' }}">
                @if ($active)
                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                @endif
            </span>
            <span class="text-sm font-medium {{ $active ? 'text-white' : 'text-slate-300' }}">{{ $label }}</span>
        </a>
    @endforeach
</div>
