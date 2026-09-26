<div class="rounded-xl border border-slate-800 bg-slate-900 overflow-x-auto scroll-thin">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-slate-500 uppercase tracking-wide border-b border-slate-800">
                <th class="px-4 py-3 font-medium">{{ __('Date & time') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Actor') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Action') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Target') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Description') }}</th>
                <th class="px-4 py-3 font-medium">{{ __('Changes') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/60">
            @forelse ($logs as $log)
                <tr class="hover:bg-slate-800/40 transition align-top">
                    <td class="px-4 py-3 whitespace-nowrap text-slate-400">{{ $log->created_at->format('M j, Y g:i:s A') }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $log->actor_name ?? __('— (system) —') }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="text-xs px-2 py-0.5 rounded-full bg-slate-800 text-slate-300 font-mono">{{ $log->action }}</span>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-slate-400">
                        {{ $log->target_type ? "{$log->target_type} #{$log->target_id}" : '—' }}
                        @if ($log->target_label)
                            <span class="block text-xs text-slate-500">{{ $log->target_label }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 max-w-sm">{{ $log->description }}</td>
                    <td class="px-4 py-3 max-w-xs">
                        @if ($log->changes)
                            <ul class="space-y-0.5 text-xs">
                                @foreach ($log->changes as $field => $value)
                                    <li>
                                        <span class="text-slate-500">{{ $field }}:</span>
                                        @if (is_array($value) && array_key_exists('old', $value) && array_key_exists('new', $value))
                                            <span class="text-red-400">{{ json_encode($value['old']) }}</span>
                                            →
                                            <span class="text-emerald-400">{{ json_encode($value['new']) }}</span>
                                        @else
                                            <span class="text-slate-300">{{ is_scalar($value) ? $value : json_encode($value) }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <span class="text-slate-600">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-slate-500">{{ __('No audit entries match these filters.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($logs->hasPages())
    <div class="mt-4">
        {{ $logs->onEachSide(1)->links() }}
    </div>
@endif
