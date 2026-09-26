{{-- shrink-0: this pill has overflow-hidden, and per the flexbox spec a flex
     item's automatic min-width is 0 (not its content size) whenever overflow
     isn't visible — without shrink-0 the nav's other items (which keep their
     content-based minimum) push this one down to near-zero width first,
     clipping EN/ES away, on narrow screens. --}}
<div class="flex items-center shrink-0 text-xs font-semibold rounded-full border border-slate-700 overflow-hidden">
    <a href="{{ route('locale.switch', 'en') }}" class="px-1.5 sm:px-2 py-1 transition {{ app()->getLocale() === 'en' ? 'bg-slate-700 text-white' : 'text-slate-400 hover:text-white' }}">EN</a>
    <a href="{{ route('locale.switch', 'es') }}" class="px-1.5 sm:px-2 py-1 transition {{ app()->getLocale() === 'es' ? 'bg-slate-700 text-white' : 'text-slate-400 hover:text-white' }}">ES</a>
</div>
