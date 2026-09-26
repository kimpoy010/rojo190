<?php

namespace App\Services;

use App\Events\BetPoolUpdated;
use App\Models\Bet;
use App\Models\Fight;
use App\Models\Game;
use App\Models\TellerShift;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Broadcaster;
use App\Support\PoolPayoutCalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BettingService
{
    public function __construct(
        private WalletService $walletService,
        private CommissionService $commissionService,
    ) {}

    /**
     * Place a pool bet. Pool bets settle against the pool total directly —
     * there is no peer-to-peer matching, so a bet is "matched" the instant
     * it's placed.
     */
    public function placeBet(User $user, Fight $fight, string $side, float $amount): Bet
    {
        if (! $fight->isBettable()) {
            throw new \InvalidArgumentException(__('Bets can only be placed on open fights.'));
        }

        if ($fight->event->status !== 'live') {
            throw new \InvalidArgumentException(__('Bets can only be placed on live events.'));
        }

        if ($side === 'draw' && ! $fight->draw_enabled) {
            throw new \InvalidArgumentException(__('Draw betting is not enabled for this fight.'));
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('Bet amount must be positive.'));
        }

        $game = $fight->event->game;

        $bet = DB::transaction(function () use ($user, $fight, $side, $amount, $game) {
            $wallet = $user->wallet()->lockForUpdate()->first();

            // Re-read fight with lock to guard against concurrent close. This
            // same lock also serializes concurrent draw bets on this fight,
            // making the pool-wide draw cap check below race-safe.
            $freshFight = Fight::lockForUpdate()->find($fight->id);
            if (! $freshFight->isBettable()) {
                throw new \InvalidArgumentException(__('Bets can only be placed on open fights.'));
            }

            if ($side === 'draw' && $game && $game->max_draw_bet > 0) {
                $existingDraw = Bet::inPool()
                    ->where('fight_id', $freshFight->id)
                    ->where('side', 'draw')
                    ->sum('amount');
                $remaining = (float) $game->max_draw_bet - (float) $existingDraw;
                if ($amount > $remaining) {
                    throw new \InvalidArgumentException($remaining > 0
                        ? __(':remaining remaining in the draw pool (limit :limit).', ['remaining' => '$'.number_format($remaining, 2), 'limit' => '$'.number_format((float) $game->max_draw_bet, 0)])
                        : __('The draw pool limit of :limit has been reached.', ['limit' => '$'.number_format((float) $game->max_draw_bet, 0)]));
                }
            }

            $bet = Bet::create([
                'user_id' => $user->id,
                'fight_id' => $freshFight->id,
                'side' => $side,
                'amount' => $amount,
                'status' => 'matched',
            ]);

            $this->walletService->debitBet(
                $wallet,
                $amount,
                'bet',
                $bet->id,
                "Bet on {$side} for Fight #{$fight->fight_number}"
            );

            return $bet;
        });

        Cache::forget("pool.fight.{$fight->id}.totals");

        $this->broadcastPoolUpdate($fight, $game, [
            'name' => $user->displayName(),
            'side' => $bet->side,
            'amount' => number_format((float) $bet->amount),
            'time' => $bet->created_at->format('H:i:s'),
        ]);

        return $bet;
    }

    /**
     * Write an over-the-counter bet ticket: a teller takes a cash stake
     * from a walk-up bettor who has no account/wallet at all. It joins the
     * same pool as every other bet on this fight (so payout percentages
     * stay correct for everyone), but nothing is debited from a wallet —
     * the "payment" is the cash the teller just physically collected,
     * which counts toward their shift's cash-in instead. A winning ticket
     * is paid out later in cash via redeemTicket(), not auto-credited.
     */
    public function placeCounterBet(User $teller, TellerShift $shift, Fight $fight, string $side, float $amount): Bet
    {
        if (! $fight->isBettable()) {
            throw new \InvalidArgumentException(__('Bets can only be placed on open fights.'));
        }

        if ($fight->event->status !== 'live') {
            throw new \InvalidArgumentException(__('Bets can only be placed on live events.'));
        }

        if ($side === 'draw' && ! $fight->draw_enabled) {
            throw new \InvalidArgumentException(__('Draw betting is not enabled for this fight.'));
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('Bet amount must be positive.'));
        }

        $game = $fight->event->game;

        $bet = DB::transaction(function () use ($teller, $shift, $fight, $side, $amount, $game) {
            $freshFight = Fight::lockForUpdate()->find($fight->id);
            if (! $freshFight->isBettable()) {
                throw new \InvalidArgumentException(__('Bets can only be placed on open fights.'));
            }

            if ($side === 'draw' && $game && $game->max_draw_bet > 0) {
                $existingDraw = Bet::inPool()
                    ->where('fight_id', $freshFight->id)
                    ->where('side', 'draw')
                    ->sum('amount');
                $remaining = (float) $game->max_draw_bet - (float) $existingDraw;
                if ($amount > $remaining) {
                    throw new \InvalidArgumentException($remaining > 0
                        ? __(':remaining remaining in the draw pool (limit :limit).', ['remaining' => '$'.number_format($remaining, 2), 'limit' => '$'.number_format((float) $game->max_draw_bet, 0)])
                        : __('The draw pool limit of :limit has been reached.', ['limit' => '$'.number_format((float) $game->max_draw_bet, 0)]));
                }
            }

            return Bet::create([
                'user_id' => null,
                'fight_id' => $freshFight->id,
                'side' => $side,
                'amount' => $amount,
                'status' => 'matched',
                'placed_by_teller_id' => $teller->id,
                'placed_teller_shift_id' => $shift->id,
                'ticket_code' => $this->generateTicketCode(),
            ]);
        });

        Cache::forget("pool.fight.{$fight->id}.totals");

        $this->broadcastPoolUpdate($fight, $game, [
            'name' => 'Counter bet',
            'side' => $bet->side,
            'amount' => number_format($amount),
            'time' => $bet->created_at->format('H:i:s'),
        ]);

        return $bet;
    }

    /**
     * Pay out a winning ticket's cash at the counter. Throws if the ticket
     * doesn't exist, hasn't won (or the fight hasn't settled yet), or was
     * already redeemed.
     */
    public function redeemTicket(string $ticketCode, User $teller, TellerShift $shift): Bet
    {
        return DB::transaction(function () use ($ticketCode, $teller, $shift) {
            $bet = Bet::where('ticket_code', $ticketCode)->lockForUpdate()->first();

            if (! $bet) {
                throw new \InvalidArgumentException(__('No ticket found for that code.'));
            }

            if (! is_null($bet->redeemed_at)) {
                throw new \InvalidArgumentException(__('This ticket has already been redeemed.'));
            }

            if ($bet->status !== 'settled') {
                throw new \InvalidArgumentException(__('This fight has not been settled yet.'));
            }

            if ((float) $bet->payout <= 0) {
                throw new \InvalidArgumentException(__('This ticket did not win — nothing to pay out.'));
            }

            $bet->update([
                'redeemed_by_teller_id' => $teller->id,
                'redeemed_teller_shift_id' => $shift->id,
                'redeemed_at' => now(),
            ]);
            $fresh = $bet->fresh();

            AuditLogger::log(
                action: 'ticket.redeemed',
                description: __('Redeemed ticket :code for :amount.', ['code' => $ticketCode, 'amount' => $fresh->payout]),
                target: $fresh,
                changes: ['redeemed_by_teller_id' => $teller->id],
                actor: $teller,
            );

            return $fresh;
        });
    }

    /**
     * Pull an over-the-counter ticket out of the pool — the teller hands
     * the stake back in cash. Admin sign-off is enforced by the caller
     * (AdminPinService resolves $approvedBy before this is ever called);
     * this just does the actual state change and re-broadcasts the pool.
     */
    public function voidBet(Bet $bet, User $teller, User $approvedBy): Bet
    {
        $voided = DB::transaction(function () use ($bet) {
            $locked = Bet::lockForUpdate()->findOrFail($bet->id);

            if (! $locked->isCounterBet()) {
                throw new \InvalidArgumentException(__('Only over-the-counter tickets can be voided this way.'));
            }

            if ($locked->status !== 'matched') {
                throw new \InvalidArgumentException(__('This ticket has already been settled or voided.'));
            }

            $fight = Fight::lockForUpdate()->findOrFail($locked->fight_id);
            if (in_array($fight->status, ['declared', 'cancelled'], true)) {
                throw new \InvalidArgumentException(__('This fight has already been settled — the ticket can no longer be voided.'));
            }

            return $locked;
        });

        $voided->update([
            'status' => 'voided',
            'voided_by_teller_id' => $teller->id,
            'voided_by_admin_id' => $approvedBy->id,
            'voided_at' => now(),
        ]);

        AuditLogger::log(
            action: 'ticket.voided',
            description: __('Voided ticket :code (:amount) — approved by :admin.', ['code' => $voided->ticket_code, 'amount' => $voided->amount, 'admin' => $approvedBy->displayName()]),
            target: $voided,
            changes: ['status' => ['old' => 'matched', 'new' => 'voided'], 'voided_by_admin_id' => $approvedBy->id],
            actor: $teller,
        );

        Cache::forget("pool.fight.{$voided->fight_id}.totals");

        $fight = $voided->fight()->with('event.game')->first();
        $this->broadcastPoolUpdate($fight, $fight->event->game, [
            'name' => 'Voided ticket',
            'side' => $voided->side,
            'amount' => number_format((float) $voided->amount),
            'time' => now()->format('H:i:s'),
        ]);

        return $voided->fresh();
    }

    /**
     * A plain sequential number (zero-padded to 10 digits) rather than a
     * random alphanumeric string — easier to read aloud, write by hand on
     * a receipt, or type back in at the redeem lookup. Called from inside
     * an existing DB transaction (placeCounterBet), so the row lock here
     * is enough to serialize concurrent tellers without opening a new one.
     */
    private function generateTicketCode(): string
    {
        $row = DB::table('ticket_sequences')->lockForUpdate()->find(1);

        if (! $row) {
            DB::table('ticket_sequences')->insert(['id' => 1, 'next_value' => 2]);
            $next = 1;
        } else {
            $next = (int) $row->next_value;
            DB::table('ticket_sequences')->where('id', 1)->update(['next_value' => $next + 1]);
        }

        return str_pad((string) $next, 10, '0', STR_PAD_LEFT);
    }

    /**
     * Recompute pool totals/payouts and push them to every live viewer of
     * this fight (kiosk, player, declarator). Called after any bet placed
     * or pulled from the pool, so this is the one place a voided bet's
     * exclusion actually has to be right.
     */
    private function broadcastPoolUpdate(Fight $fight, ?Game $game, array $latestBet = []): void
    {
        $pools = Bet::inPool()->where('fight_id', $fight->id)
            ->selectRaw("
                SUM(CASE WHEN side='meron' THEN amount ELSE 0 END) as meron,
                SUM(CASE WHEN side='wala'  THEN amount ELSE 0 END) as wala,
                SUM(CASE WHEN side='draw'  THEN amount ELSE 0 END) as draw
            ")
            ->first();

        $plasada = $game ? (float) $game->plasada : 5.00;
        $plasadaMode = $game?->plasada_mode ?? 'total_pool';
        $payouts = PoolPayoutCalculator::calculate((float) $pools->meron, (float) $pools->wala, $plasada, $plasadaMode);

        Broadcaster::send(new BetPoolUpdated(
            $fight->id,
            (float) $pools->meron,
            (float) $pools->wala,
            (float) $pools->draw,
            $payouts['meron'],
            $payouts['wala'],
            $latestBet,
            $payouts['meron_pct'],
            $payouts['wala_pct'],
        ));
    }

    /**
     * Refund the full original bet amount to every bettor on the fight. Used
     * when a fight is cancelled.
     */
    public function refundAll(Fight $fight): void
    {
        DB::transaction(function () use ($fight) {
            $bets = Bet::inPool()->where('fight_id', $fight->id)
                ->where(function ($q) {
                    // Skip a counter bet already redeemed at the full
                    // refund amount — nothing left to give back.
                    $q->whereNull('user_id')->whereNull('redeemed_at')
                        ->orWhereNotNull('user_id');
                })
                ->lockForUpdate()
                ->with('user.wallet')
                ->get();

            foreach ($bets as $bet) {
                if ($bet->isCounterBet()) {
                    // No wallet to credit — the stake becomes redeemable
                    // in cash at the counter instead.
                    $bet->update(['status' => 'settled', 'payout' => $bet->amount]);

                    continue;
                }

                $this->walletService->creditBet(
                    $bet->user->wallet,
                    $bet->amount,
                    'refund',
                    $bet->id,
                    "Fight #{$fight->fight_number} cancelled — full refund"
                );
                $bet->update(['status' => 'refunded']);
            }
        });
    }

    /**
     * Reverse a fight's settlement: debit every credited payout back from the
     * winner's wallet (unchecked — wallet may go negative), then reset all
     * bets to 'matched' so they can be re-settled or refunded.
     *
     * A counter-bet ticket already redeemed in cash is left untouched —
     * that money physically left the drawer and can't be clawed back
     * automatically, so it's excluded from the reset entirely (still
     * shows its original result/payout, just no longer re-settleable).
     */
    public function reverseSettlement(Fight $fight): void
    {
        DB::transaction(function () use ($fight) {
            $settledBets = Bet::where('fight_id', $fight->id)
                ->where('payout', '>', 0)
                ->whereNotNull('user_id')
                ->lockForUpdate()
                ->with('user.wallet')
                ->get();

            foreach ($settledBets as $bet) {
                $this->walletService->debitUnchecked(
                    $bet->user->wallet,
                    $bet->payout,
                    'reversal',
                    $bet->id,
                    "Payout reversal for Fight #{$fight->fight_number}"
                );
            }

            Bet::where('fight_id', $fight->id)
                // A voided ticket never went through settlement in the
                // first place — leave it voided, don't pull it back into
                // circulation just because the fight is being re-declared.
                ->where('status', '!=', 'voided')
                ->where(function ($q) {
                    $q->whereNotNull('user_id')->orWhereNull('redeemed_at');
                })
                ->update(['status' => 'matched', 'payout' => null]);
        });
    }

    public function settleBets(Fight $fight, string $winner): void
    {
        if (! in_array($winner, ['meron', 'wala', 'draw'], true)) {
            throw new \InvalidArgumentException(__('Invalid winner value: :winner. Must be meron, wala, or draw.', ['winner' => $winner]));
        }

        $game = $fight->event->game;

        DB::transaction(function () use ($fight, $winner, $game) {
            Fight::lockForUpdate()->findOrFail($fight->id);

            if (Bet::where('fight_id', $fight->id)->where('status', 'settled')->exists()) {
                return; // already settled
            }

            $plasada = (float) ($game->plasada ?? 5.00);
            $multiplier = (float) ($game->draw_multiplier ?? 8.00);

            if ($winner === 'draw') {
                $this->settleDraw($fight, $multiplier);

                return;
            }

            $this->settleMeronOrWala($fight, $winner, $plasada, $game->plasada_mode ?? 'total_pool');
        });
    }

    private function settleDraw(Fight $fight, float $multiplier): void
    {
        // Meron/wala bettors don't lose on a draw — refund their stake.
        // (A voided ticket is excluded — it was already handed back in cash.)
        Bet::inPool()->where('fight_id', $fight->id)
            ->whereIn('side', ['meron', 'wala'])
            ->with('user.wallet')
            ->lockForUpdate()
            ->get()
            ->each(function (Bet $bet) use ($fight) {
                if ($bet->isCounterBet()) {
                    $bet->update(['status' => 'settled', 'payout' => $bet->amount]);

                    return;
                }

                $this->walletService->creditBet(
                    $bet->user->wallet,
                    $bet->amount,
                    'refund',
                    $bet->id,
                    "Draw declared — refund Fight #{$fight->fight_number}"
                );
                $bet->update(['status' => 'settled', 'payout' => $bet->amount]);
            });

        // Draw bettors win a fixed multiplier, funded from the superadmin's
        // wallet (a house-funded side bet, not pari-mutuel). Counter-bet
        // tickets share the same multiplier but are paid in cash on
        // redemption, not from the admin wallet, so they're excluded from
        // the funding debit — that cash already left the teller's drawer
        // the moment the ticket is redeemed.
        $drawBets = Bet::inPool()->where('fight_id', $fight->id)
            ->where('side', 'draw')
            ->with('user.wallet')
            ->lockForUpdate()
            ->get();

        $totalDrawPaid = (int) $drawBets->filter(fn ($b) => ! $b->isCounterBet())
            ->sum(fn ($b) => floor($b->amount * $multiplier));

        if ($totalDrawPaid > 0) {
            $admin = User::role('superadmin')->first();
            if ($admin?->wallet) {
                $this->walletService->debitBet(
                    $admin->wallet,
                    $totalDrawPaid,
                    'draw_payout',
                    null,
                    "Draw payouts funded Fight #{$fight->fight_number}"
                );
            }
        }

        foreach ($drawBets as $bet) {
            $payout = (int) floor($bet->amount * $multiplier);

            if ($bet->isCounterBet()) {
                $bet->update(['status' => 'settled', 'payout' => $payout]);

                continue;
            }

            $this->walletService->creditBet(
                $bet->user->wallet,
                $payout,
                'payout',
                $bet->id,
                "Draw wins Fight #{$fight->fight_number}"
            );
            $bet->update(['status' => 'settled', 'payout' => $payout]);
        }
    }

    private function settleMeronOrWala(Fight $fight, string $winner, float $plasada, string $plasadaMode): void
    {
        $losingSide = $winner === 'meron' ? 'wala' : 'meron';
        $meronPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'meron')->sum('amount');
        $walaPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'wala')->sum('amount');

        $poolPayouts = PoolPayoutCalculator::calculate($meronPool, $walaPool, $plasada, $plasadaMode);
        $payoutRatio = $winner === 'meron' ? $poolPayouts['meron'] : $poolPayouts['wala'];

        // Pool is so one-sided that winners would receive less than they bet.
        // Void the round: refund everyone, take no plasada.
        if ($payoutRatio < 1.0) {
            $this->refundAll($fight);
            $fight->update(['status' => 'cancelled', 'winner' => null]);

            return;
        }

        // Losing side + draw bettors lose.
        Bet::inPool()->where('fight_id', $fight->id)
            ->whereIn('side', [$losingSide, 'draw'])
            ->update(['status' => 'settled', 'payout' => 0.00]);

        $winnerPool = $winner === 'meron' ? $meronPool : $walaPool;
        if ($winnerPool <= 0) {
            Bet::inPool()->where('fight_id', $fight->id)
                ->where('side', $winner)
                ->update(['status' => 'settled', 'payout' => 0.00]);

            return;
        }

        // Pay winning bets (pari-mutuel). Payout is floored to whole currency
        // units — a winning bettor is never paid less than their stake since
        // payoutRatio >= 1.0 was already enforced above.
        $winningBets = Bet::inPool()->where('fight_id', $fight->id)
            ->where('side', $winner)
            ->with('user.wallet')
            ->lockForUpdate()
            ->get();

        foreach ($winningBets as $bet) {
            $payout = (int) floor($bet->amount * $payoutRatio);

            if ($bet->isCounterBet()) {
                // No wallet to credit — the ticket becomes redeemable in
                // cash at the counter instead.
                $bet->update(['status' => 'settled', 'payout' => $payout]);

                continue;
            }

            $this->walletService->creditBet(
                $bet->user->wallet,
                $payout,
                'payout',
                $bet->id,
                ucfirst($winner)." wins Fight #{$fight->fight_number}"
            );
            $bet->update(['status' => 'settled', 'payout' => $payout]);
        }

        // Commission is earned on every meron/wala bet — winning or losing —
        // based on the actual amount staked. Draw bets and void-round refunds
        // never generate commission. Counter-bet tickets have no player/agent
        // relationship at all, so they're excluded entirely.
        Bet::inPool()->where('fight_id', $fight->id)
            ->whereIn('side', ['meron', 'wala'])
            ->whereNotNull('user_id')
            ->get()
            ->each(fn (Bet $bet) => $this->commissionService->distributeCommission($bet, (float) $bet->amount));
    }
}
