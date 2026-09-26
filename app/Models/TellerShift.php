<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TellerShift extends Model
{
    protected $fillable = [
        'teller_id',
        'starting_cash',
        'status',
        'started_at',
        'ended_at',
        'ending_cash',
        'expected_cash',
        'variance',
    ];

    protected function casts(): array
    {
        return [
            'starting_cash' => 'decimal:2',
            'ending_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_id');
    }

    public function cashTransactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }

    /**
     * Counter-bet tickets written during this shift — their stakes are
     * cash the teller took in on the spot.
     */
    public function ticketsWritten(): HasMany
    {
        return $this->hasMany(Bet::class, 'placed_teller_shift_id');
    }

    /**
     * Winning tickets paid out in cash during this shift (may have been
     * written in an earlier shift, even by a different teller).
     */
    public function ticketsRedeemed(): HasMany
    {
        return $this->hasMany(Bet::class, 'redeemed_teller_shift_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Running totals for this shift: cash the teller has taken in
     * (deposits, counter-bet stakes) and paid out (withdrawals, winning
     * ticket redemptions). Used both live (dashboard, end-of-shift
     * confirmation) and frozen into expected_cash/variance once the shift
     * is closed.
     */
    public function totals(): array
    {
        $raw = $this->cashTransactions()
            ->where('status', 'completed')
            ->selectRaw("
                SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) as deposits,
                SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) as withdrawals,
                SUM(CASE WHEN type = 'deposit' THEN 1 ELSE 0 END) as deposit_count,
                SUM(CASE WHEN type = 'withdrawal' THEN 1 ELSE 0 END) as withdrawal_count
            ")
            ->first();

        $deposits = (float) ($raw->deposits ?? 0);
        $withdrawals = (float) ($raw->withdrawals ?? 0);

        // A voided ticket's cash was handed back to the bettor, so it no
        // longer counts as cash the teller is holding for this shift.
        $ticketStakes = (float) $this->ticketsWritten()->where('status', '!=', 'voided')->sum('amount');
        $ticketStakeCount = (int) $this->ticketsWritten()->where('status', '!=', 'voided')->count();
        $voidedTicketCount = (int) $this->ticketsWritten()->where('status', 'voided')->count();

        $ticketPayouts = (float) $this->ticketsRedeemed()->whereNotNull('redeemed_at')->sum('payout');
        $ticketRedeemedCount = (int) $this->ticketsRedeemed()->whereNotNull('redeemed_at')->count();

        return [
            'deposits' => $deposits,
            'withdrawals' => $withdrawals,
            'deposit_count' => (int) ($raw->deposit_count ?? 0),
            'withdrawal_count' => (int) ($raw->withdrawal_count ?? 0),
            'ticket_stakes' => $ticketStakes,
            'ticket_stake_count' => $ticketStakeCount,
            'voided_ticket_count' => $voidedTicketCount,
            'ticket_payouts' => $ticketPayouts,
            'ticket_redeemed_count' => $ticketRedeemedCount,
            'expected_cash' => (float) $this->starting_cash + $deposits + $ticketStakes - $withdrawals - $ticketPayouts,
        ];
    }
}
