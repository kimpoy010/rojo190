<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs text-slate-500 mb-1 uppercase tracking-wide">{{ __('Total staked') }}</p>
        <p class="text-xl font-extrabold">{{ $currencySymbol }}{{ number_format($summary->staked, 2) }}</p>
    </div>
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs text-slate-500 mb-1 uppercase tracking-wide">{{ __('Total paid out') }}</p>
        <p class="text-xl font-extrabold">{{ $currencySymbol }}{{ number_format($summary->paid_out, 2) }}</p>
    </div>
    <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
        <p class="text-xs text-slate-500 mb-1 uppercase tracking-wide">{{ __('Net income') }}</p>
        @php $netTotal = $summary->staked - $summary->paid_out; @endphp
        <p class="text-xl font-extrabold {{ $netTotal >= 0 ? 'text-emerald-400' : 'text-red-400' }}">{{ $currencySymbol }}{{ number_format($netTotal, 2) }}</p>
    </div>
</div>

<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-x-auto scroll-thin">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-slate-500 uppercase tracking-wide border-b border-slate-800">
                <th class="px-4 py-3 font-medium">{{ __('Event') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Fight') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Result') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Staked') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Paid out') }}</th>
                <th class="px-4 py-3 font-medium text-right">{{ __('Net income') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Declared') }}</th>
                <th class="px-4 py-3 font-medium"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/60">
            @forelse ($fights as $fight)
                @php $net = (float) $fight->staked - (float) $fight->paid_out; @endphp
                <tr class="hover:bg-slate-800/40 transition">
                    <td class="px-4 py-3 whitespace-nowrap">{{ $fight->event->name }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ __('Fight #:number', ['number' => $fight->fight_number]) }}</td>
                    <td class="px-4 py-3">
                        @if ($fight->status === 'cancelled')
                            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-700">{{ __('CANCELLED') }}</span>
                        @else
                            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-700 uppercase">{{ $fight->winner ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">{{ $currencySymbol }}{{ number_format($fight->staked, 2) }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">{{ $currencySymbol }}{{ number_format($fight->paid_out, 2) }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap font-semibold {{ $net >= 0 ? 'text-emerald-400' : 'text-red-400' }}">{{ $currencySymbol }}{{ number_format($net, 2) }}</td>
                    <td class="px-4 py-3 whitespace-nowrap text-slate-500">{{ $fight->declared_at?->format('M j, Y g:i A') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('superadmin.reports.accounting.fight', $fight) }}" class="text-red-400 hover:underline">{{ __('View bets') }}</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-4 py-6 text-center text-slate-500">{{ __('No settled fights yet.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($fights->hasPages())
        <div class="p-4 border-t border-slate-800">
            {{ $fights->links() }}
        </div>
    @endif
</div>
