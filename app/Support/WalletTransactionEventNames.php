<?php

namespace App\Support;

use App\Models\Bet;
use Illuminate\Support\Collection;

/**
 * Resolves the event name behind a bet-linked wallet transaction (a bet
 * placement, its payout, a refund, or a reversed payout) — every one of
 * these has reference_id set to a Bet id, so one batched query answers it
 * for a whole page of transactions instead of a lookup per row. Shared by
 * the player's own wallet page and the superadmin per-player view.
 */
class WalletTransactionEventNames
{
    public const BET_LINKED_REFERENCE_TYPES = ['bet', 'payout', 'refund', 'reversal'];

    /**
     * @param  Collection<int, \App\Models\WalletTransaction>  $transactions
     * @return Collection<int, string> event name keyed by bet id
     */
    public static function forTransactions(Collection $transactions): Collection
    {
        $betIds = self::betIdsFor($transactions);

        if ($betIds->isEmpty()) {
            return collect();
        }

        return Bet::whereIn('id', $betIds)
            ->with('fight.event:id,name')
            ->get()
            ->mapWithKeys(fn (Bet $bet) => [$bet->id => $bet->fight->event->name]);
    }

    /**
     * A short "<Side> - Fight #<n>" one-liner per bet-linked transaction,
     * region-aware (pulls the event's own label_meron/wala/draw rather than
     * a hardcoded English word) with a text color class matching that
     * side's theme — built for the player wallet page's transaction list,
     * whose stored `description` text is often too long to show on one
     * line without wrapping or clipping. Purely a display concern: the
     * stored description itself is untouched and still used everywhere
     * else (the transaction detail modal, CSV exports, etc.).
     *
     * @param  Collection<int, \App\Models\WalletTransaction>  $transactions
     * @return Collection<int, array{label: string, colorClass: string}> keyed by bet id
     */
    public static function betSummaries(Collection $transactions): Collection
    {
        $betIds = self::betIdsFor($transactions);

        if ($betIds->isEmpty()) {
            return collect();
        }

        return Bet::whereIn('id', $betIds)
            ->with('fight.event.game')
            ->get()
            ->mapWithKeys(function (Bet $bet) {
                $event = $bet->fight->event;
                $theme = $event->game?->theme() ?? GameTheme::for(null);

                return [$bet->id => [
                    'label' => $event->sideLabel($bet->side).' - '.__('Fight #:number', ['number' => $bet->fight->fight_number]),
                    'colorClass' => $theme[$bet->side]['soft_text'] ?? 'text-teal-300',
                ]];
            });
    }

    /**
     * @param  Collection<int, \App\Models\WalletTransaction>  $transactions
     * @return Collection<int, int>
     */
    private static function betIdsFor(Collection $transactions): Collection
    {
        return $transactions
            ->filter(fn ($tx) => in_array($tx->reference_type, self::BET_LINKED_REFERENCE_TYPES, true) && $tx->reference_id)
            ->pluck('reference_id')
            ->unique();
    }
}
