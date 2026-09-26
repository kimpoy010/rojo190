{{--
    A single flatpickr range control backed by two hidden inputs carrying
    the real from/to field names — every date-range filter in the app uses
    this instead of a pair of native <input type="date"> fields (see
    resources/js/date-range-picker.js for the widget itself).

    Expected variables:
      $id         unique prefix for this field's hidden input ids
      $fromName   the "from" hidden input's name attribute
      $toName     the "to" hidden input's name attribute
      $fromValue  current "from" value (Y-m-d or null)
      $toValue    current "to" value (Y-m-d or null)
      $dateRangeLabel  optional label override (defaults to "Date range")
--}}
<div>
    <label class="block text-xs text-slate-500 mb-1">{{ $dateRangeLabel ?? __('Date range') }}</label>
    <div class="relative">
        <input type="text" data-date-range
               data-range-from="#{{ $id }}-from" data-range-to="#{{ $id }}-to"
               placeholder="{{ __('Select dates') }}" autocomplete="off" readonly
               class="rounded-lg bg-slate-800 border border-slate-700 pl-3 pr-6 py-2 text-sm w-64 cursor-pointer">
        <button type="button" data-date-range-clear hidden
                class="absolute right-1.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white text-sm leading-none">&times;</button>
    </div>
    <input type="hidden" id="{{ $id }}-from" name="{{ $fromName }}" value="{{ $fromValue }}">
    <input type="hidden" id="{{ $id }}-to" name="{{ $toName }}" value="{{ $toValue }}">
</div>
