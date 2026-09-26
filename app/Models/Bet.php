<?php

namespace App\Models;

use App\Models\Concerns\HasHashChain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bet extends Model
{
    use HasHashChain;

    protected $fillable = [
        'user_id',
        'fight_id',
        'side',
        'odds_tier_id',
        'amount',
        'matched_amount',
        'unmatched_amount',
        'status',
        'payout',
        'placed_by_teller_id',
        'placed_teller_shift_id',
        'ticket_code',
        'redeemed_by_teller_id',
        'redeemed_teller_shift_id',
        'redeemed_at',
        'voided_by_teller_id',
        'voided_by_admin_id',
        'voided_at',
    ];

    /**
     * Only the wager as it was placed — status, payout, and every
     * redeemed- and voided- column are set later at settlement,
     * redemption, or void, and aren't part of the tamper-evident creation
     * fact.
     */
    public function hashChainFields(): array
    {
        return ['user_id', 'fight_id', 'side', 'amount', 'placed_by_teller_id', 'placed_teller_shift_id', 'ticket_code'];
    }

    /**
     * One chain per player for an account bet — placeBet() already locks
     * that player's wallet first, so this rides the same lock. A counter
     * bet has no user (isCounterBet()), so it chains per teller shift
     * instead, which is the closest thing it has to a natural owner.
     */
    public function hashChainScope(): string
    {
        return $this->user_id !== null
            ? 'user:'.$this->user_id
            : 'teller-shift:'.$this->placed_teller_shift_id;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'matched_amount' => 'decimal:2',
            'unmatched_amount' => 'decimal:2',
            'payout' => 'decimal:2',
            'redeemed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Set only for a CombinedSabong fixed-odds bet — the ratio it's
     * matched against the opposing side at. Null for a pool bet (every
     * bet on every other game, and draw bets on either game).
     */
    public function oddsTier(): BelongsTo
    {
        return $this->belongsTo(OddsTier::class);
    }

    /**
     * Bets that still count toward the pool total / payout odds — excludes
     * a voided ticket (pulled by admin-approved teller action) and a
     * refunded bet (fight-cancellation refund). Used everywhere pool
     * totals are summed or settled so a voided bet vanishes from the math
     * immediately, not just from the UI.
     */
    public function scopeInPool(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['voided', 'refunded']);
    }

    /**
     * What a player's own "Betting history" row shows for this bet's
     * outcome — 'status' alone doesn't distinguish won from lost (both
     * land as 'settled'; payout is what tells them apart).
     *
     * @return array{label: string, class: string}
     */
    public function historyStatusLabel(): array
    {
        return match (true) {
            $this->status === 'matched' => ['label' => __('Pending'), 'class' => 'text-amber-400'],
            $this->status === 'settled' && (float) $this->payout > 0 => ['label' => __('Won :amount', ['amount' => '$'.number_format((float) $this->payout, 2)]), 'class' => 'text-emerald-400'],
            $this->status === 'settled' => ['label' => __('Lost'), 'class' => 'text-red-400'],
            $this->status === 'voided' => ['label' => __('Voided'), 'class' => 'text-slate-500'],
            $this->status === 'refunded' => ['label' => __('Refunded'), 'class' => 'text-slate-500'],
            default => ['label' => ucfirst($this->status), 'class' => 'text-slate-500'],
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fight(): BelongsTo
    {
        return $this->belongsTo(Fight::class);
    }

    public function placedByTeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_teller_id');
    }

    public function placedTellerShift(): BelongsTo
    {
        return $this->belongsTo(TellerShift::class, 'placed_teller_shift_id');
    }

    public function redeemedByTeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_teller_id');
    }

    public function redeemedTellerShift(): BelongsTo
    {
        return $this->belongsTo(TellerShift::class, 'redeemed_teller_shift_id');
    }

    public function voidedByTeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_teller_id');
    }

    public function voidedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_admin_id');
    }

    /**
     * A counter/ticket bet: written by a teller for a walk-up bettor with
     * no account of their own, redeemable in cash via ticket_code rather
     * than auto-paid to a wallet.
     */
    public function isCounterBet(): bool
    {
        return is_null($this->user_id);
    }

    /**
     * A winning counter bet whose cash hasn't been claimed yet.
     */
    public function isRedeemable(): bool
    {
        return $this->isCounterBet()
            && $this->status === 'settled'
            && (float) $this->payout > 0
            && is_null($this->redeemed_at);
    }

    /**
     * A counter bet can be voided while it's still unsettled and its fight
     * hasn't been declared/cancelled — see BettingService::voidBet().
     */
    public function isVoidable(): bool
    {
        return $this->isCounterBet()
            && $this->status === 'matched'
            && ! in_array($this->fight->status, ['declared', 'cancelled'], true);
    }
}
