@php
    ['referenceLabels' => $referenceLabels, 'actionLabels' => $actionLabels, 'txIcons' => $txIcons] = \App\Support\WalletTransactionDisplay::maps();
@endphp

<div class="divide-y divide-[#2a1a14]">
    @forelse ($transactions ?? [] as $tx)
        @php
            $title = $tx->description ?: ($referenceLabels[$tx->reference_type] ?? ucfirst($tx->reference_type ?? $tx->type));
            $action = $actionLabels[$tx->reference_type] ?? ($tx->type === 'credit' ? __('Credited') : __('Debited'));
            $isCredit = $tx->type === 'credit';
            $icon = $txIcons[$tx->reference_type] ?? ['path' => 'M12 8v4m0 4h.01', 'color' => '#8a7a70'];
            // reference_id is only ever a Bet id for these types — for
            // others (a deposit's CashTransaction id, say) it could
            // coincidentally collide with an unrelated Bet's id, so the
            // lookup must stay gated by type, not just "is it present".
            $isBetLinked = in_array($tx->reference_type, \App\Support\WalletTransactionEventNames::BET_LINKED_REFERENCE_TYPES, true);
            $eventName = $isBetLinked ? ($eventNamesByBetId[$tx->reference_id] ?? null) : null;
            // The stored description (above) is often too long for this
            // one-line row ("Bet on wala for Fight #5 ...", "Manual top-up
            // by superadmin") — every row instead gets a short label:
            // bet-linked rows with a resolvable bet get a region-aware
            // "<Side> - Fight #<n>" colored to match that side's theme;
            // everything else (deposits, withdrawals, admin adjustments,
            // and any bet-linked row whose bet can't be resolved — legacy
            // data, or a since-deleted bet) falls back to the short
            // reference-type label already used elsewhere on this page,
            // tinted with that type's own icon color, with a "- Fight #n"
            // suffix salvaged from the description when one's there. The
            // full original description still shows in the detail modal
            // via $title/data-title regardless.
            $betSummary = $isBetLinked ? ($betSummaries[$tx->reference_id] ?? null) : null;
            if ($betSummary) {
                $rowLabel = $betSummary['label'];
                $rowColorStyle = null;
                $rowColorClass = $betSummary['colorClass'];
            } else {
                $rowLabel = $referenceLabels[$tx->reference_type] ?? ucfirst($tx->reference_type ?? $tx->type);
                if (preg_match('/Fight #(\d+)/u', (string) $tx->description, $fightMatch)) {
                    $rowLabel .= ' - '.__('Fight #:number', ['number' => $fightMatch[1]]);
                }
                $rowColorStyle = $icon['color'];
                $rowColorClass = null;
            }
        @endphp
        <button type="button"
            class="wallet-tx-row w-full text-left px-1 py-3 hover:bg-white/[0.03] rounded-lg transition flex items-center gap-3"
            data-title="{{ $title }}"
            data-action="{{ $action }}"
            data-type="{{ $tx->type }}"
            data-amount="{{ number_format($tx->amount, 2) }}"
            data-balance="{{ number_format($tx->balance_after, 2) }}"
            data-reference-type="{{ $tx->reference_type ?? '—' }}"
            data-reference-id="{{ $tx->reference_id ?? '—' }}"
            data-event-name="{{ $eventName }}"
            data-datetime="{{ $tx->created_at->format('F j, Y \a\t g:i A') }}">
            <span class="w-8 h-8 rounded-full flex items-center justify-center shrink-0" style="background: {{ $icon['color'] }}1f;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="{{ $icon['color'] }}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon['path'] }}"/></svg>
            </span>
            <span class="flex-1 min-w-0">
                <span class="flex items-center justify-between text-xs text-[#8a7a70]">
                    <span>{{ __(':action on', ['action' => $action]) }}</span>
                    <span>{{ $tx->created_at->format('h:i A') }}</span>
                </span>
                <span class="flex items-center justify-between mt-1 gap-3">
                    <span class="font-semibold truncate {{ $rowColorClass }}"{!! $rowColorStyle ? ' style="color: '.e($rowColorStyle).'"' : '' !!}>{{ $rowLabel }}</span>
                    <span class="font-semibold whitespace-nowrap {{ $isCredit ? 'text-emerald-400' : 'text-red-400' }}">
                        {{ $isCredit ? '+' : '-' }}{{ $currencySymbol }}{{ number_format($tx->amount, 2) }}
                    </span>
                </span>
            </span>
        </button>
    @empty
        <p class="py-4 px-1 text-[#8a7a70] text-sm">{{ __('No wallet activity yet.') }}</p>
    @endforelse
</div>

@if ($transactions && $transactions->hasPages())
    <div class="mt-4">
        {{ $transactions->onEachSide(1)->links() }}
    </div>
@endif
