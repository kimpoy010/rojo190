<?php

namespace App\Services;

use App\Events\WalletBalanceUpdated;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\AuditLogger;
use App\Support\Broadcaster;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Queued to fire only once the outermost transaction actually commits —
     * callers here are frequently nested inside a larger transaction (bet
     * settlement, a teller's cash approval), and a balance that gets rolled
     * back should never have been broadcast as final.
     */
    private function broadcastBalance(Wallet $wallet): void
    {
        DB::afterCommit(fn () => Broadcaster::send(new WalletBalanceUpdated($wallet)));
    }

    /**
     * Every WalletTransaction write below funnels through here — one place
     * to both create the ledger row and audit-log it, instead of repeating
     * both at each of the 8 call sites. The audit entry's target is the
     * wallet's owner (who the money moved for), not the actor (who
     * triggered it, e.g. a declarator settling everyone's bets) — those
     * two are often different people, and AuditLogger::log() already
     * defaults the actor to whoever is currently authenticated.
     */
    private function record(Wallet $wallet, string $type, float $amount, float $balanceAfter, string $referenceType, ?int $referenceId, string $description): WalletTransaction
    {
        $tx = WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
        ]);

        AuditLogger::log(
            action: 'wallet.'.$referenceType,
            description: $description,
            target: $wallet->user,
            changes: ['type' => $type, 'amount' => $amount, 'balance_after' => $balanceAfter],
        );

        return $tx;
    }

    /**
     * Debit a wallet-initiated action (e.g. manual admin adjustment). Respects
     * the wallet lock and enforces a sufficient balance.
     */
    public function debit(Wallet $wallet, float $amount, string $referenceType, ?int $referenceId, string $description): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description) {
            $locked = Wallet::lockForUpdate()->findOrFail($wallet->id);

            if ($locked->is_locked) {
                throw new \RuntimeException(__('Wallet is locked and cannot process debits.'));
            }
            if ($locked->main_balance < $amount) {
                throw new \RuntimeException(__('Insufficient balance. Available: :available, required: :required.', ['available' => $locked->main_balance, 'required' => $amount]));
            }

            $locked->decrement('main_balance', $amount);
            $this->broadcastBalance($locked);

            return $this->record($locked, 'debit', $amount, $locked->main_balance, $referenceType, $referenceId, $description);
        });
    }

    /**
     * Credit a wallet (top-up, admin adjustment). Incoming money always goes
     * through even if the wallet is locked — a lock only blocks outgoing money.
     */
    public function credit(Wallet $wallet, float $amount, string $referenceType, ?int $referenceId, string $description): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description) {
            $locked = Wallet::lockForUpdate()->findOrFail($wallet->id);

            $locked->increment('main_balance', $amount);
            $this->broadcastBalance($locked);

            return $this->record($locked, 'credit', $amount, $locked->main_balance, $referenceType, $referenceId, $description);
        });
    }

    /**
     * Credit for a bet payout or refund — bypasses the wallet lock so that
     * winnings/refunds always reach the player even if their wallet is locked.
     */
    public function creditBet(Wallet $wallet, float $amount, string $referenceType, ?int $referenceId, string $description): WalletTransaction
    {
        return $this->credit($wallet, $amount, $referenceType, $referenceId, $description);
    }

    /**
     * Debit for a bet placement — bypasses the wallet lock so players can
     * always bet even when their wallet is locked (lock only blocks admin
     * withdrawals/adjustments). Enforces sufficient *available* balance —
     * main_balance minus any amount reserved by a pending withdrawal
     * request — so a player can't bet away funds they've already queued
     * up to cash out at the teller.
     */
    public function debitBet(Wallet $wallet, float $amount, string $referenceType, ?int $referenceId, string $description): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description) {
            $locked = Wallet::lockForUpdate()->findOrFail($wallet->id);

            if ($locked->availableBalance() < $amount) {
                throw new \RuntimeException(__('Insufficient balance. Available: :available, required: :required.', ['available' => $locked->availableBalance(), 'required' => $amount]));
            }

            $locked->decrement('main_balance', $amount);
            $this->broadcastBalance($locked);

            return $this->record($locked, 'debit', $amount, $locked->main_balance, $referenceType, $referenceId, $description);
        });
    }

    /**
     * Debit that bypasses BOTH the wallet lock and the balance check. Used only
     * for system-driven payout reversals during a re-declare — a player must
     * not be able to block a clawback by locking their wallet, and the balance
     * may legitimately go negative. Floored to prevent runaway negative balances.
     */
    public function debitUnchecked(Wallet $wallet, float $amount, string $referenceType, ?int $referenceId, string $description): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description) {
            $locked = Wallet::lockForUpdate()->findOrFail($wallet->id);

            if (($locked->main_balance - $amount) < -999999) {
                throw new \RuntimeException(__('Debit would exceed maximum negative balance floor.'));
            }

            $locked->decrement('main_balance', $amount);
            $this->broadcastBalance($locked);

            return $this->record($locked, 'debit', $amount, $locked->main_balance, $referenceType, $referenceId, $description);
        });
    }

    /**
     * Credit an agent's commission balance. System-generated — bypasses the
     * wallet lock so a locked agent wallet never blocks a player's bet
     * placement (which triggers commission distribution in the same
     * settlement transaction).
     */
    public function creditCommission(Wallet $wallet, float $amount, string $referenceType, ?int $referenceId, string $description): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description) {
            $locked = Wallet::lockForUpdate()->findOrFail($wallet->id);

            $locked->increment('commission_balance', $amount);
            $this->broadcastBalance($locked);

            return $this->record($locked, 'credit', $amount, $locked->commission_balance, $referenceType, $referenceId, $description);
        });
    }

    /**
     * Debit an agent's commission balance (e.g. reversal). Respects the
     * wallet lock and enforces a sufficient commission balance.
     */
    public function debitCommission(Wallet $wallet, float $amount, string $referenceType, ?int $referenceId, string $description): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $referenceType, $referenceId, $description) {
            $locked = Wallet::lockForUpdate()->findOrFail($wallet->id);

            if ($locked->is_locked) {
                throw new \RuntimeException(__('Wallet is locked and cannot process withdrawals.'));
            }
            if ($locked->commission_balance < $amount) {
                throw new \RuntimeException(__('Insufficient commission balance. Available: :available.', ['available' => '$'.number_format($locked->commission_balance, 2)]));
            }

            $locked->decrement('commission_balance', $amount);
            $this->broadcastBalance($locked);

            return $this->record($locked, 'debit', $amount, $locked->commission_balance, $referenceType, $referenceId, $description);
        });
    }

    /**
     * Reserve an amount against a pending withdrawal request — holds it out
     * of the player's available (bettable) balance without touching
     * main_balance. Throws if the available balance can't cover it.
     */
    public function reserveWithdrawal(Wallet $wallet, float $amount): void
    {
        DB::transaction(function () use ($wallet, $amount) {
            $locked = Wallet::lockForUpdate()->findOrFail($wallet->id);

            if ($locked->availableBalance() < $amount) {
                throw new \RuntimeException(__('Insufficient available balance to reserve for withdrawal.'));
            }

            $locked->increment('pending_withdrawal', $amount);
        });

        AuditLogger::log(
            action: 'wallet.withdrawal_reserved',
            description: __('Reserved :amount pending a withdrawal request.', ['amount' => $amount]),
            target: $wallet->user,
            changes: ['pending_withdrawal' => ['delta' => $amount]],
        );
    }

    /**
     * Release a reservation without moving any money — used when a pending
     * withdrawal request is cancelled or expires unapproved.
     */
    public function releaseWithdrawalReservation(Wallet $wallet, float $amount): void
    {
        DB::transaction(function () use ($wallet, $amount) {
            Wallet::lockForUpdate()->findOrFail($wallet->id)->decrement('pending_withdrawal', $amount);
        });

        AuditLogger::log(
            action: 'wallet.withdrawal_reservation_released',
            description: __('Released a :amount withdrawal reservation.', ['amount' => $amount]),
            target: $wallet->user,
            changes: ['pending_withdrawal' => ['delta' => -$amount]],
        );
    }

    /**
     * Complete an approved withdrawal: actually debits main_balance and
     * releases the matching reservation in one step. This is the only place
     * a withdrawal ever touches main_balance — reserving it at QR-generation
     * time does not.
     */
    public function completeWithdrawal(Wallet $wallet, float $amount, ?int $referenceId, string $description): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $referenceId, $description) {
            $locked = Wallet::lockForUpdate()->findOrFail($wallet->id);

            $locked->decrement('main_balance', $amount);
            $locked->decrement('pending_withdrawal', $amount);
            $this->broadcastBalance($locked);

            return $this->record($locked, 'debit', $amount, $locked->main_balance, 'withdrawal', $referenceId, $description);
        });
    }

    /**
     * Move an agent's entire commission balance into their main balance.
     * Returns the amount transferred.
     */
    public function transferCommissionToMain(Wallet $wallet): float
    {
        return DB::transaction(function () use ($wallet) {
            $locked = Wallet::lockForUpdate()->findOrFail($wallet->id);

            if ($locked->is_locked) {
                throw new \RuntimeException(__('Wallet is locked. Unlock it before transferring commission.'));
            }
            if ($locked->commission_balance < 0.01) {
                throw new \RuntimeException(__('Commission balance too low to transfer.'));
            }

            $amount = (float) $locked->commission_balance;

            $locked->decrement('commission_balance', $amount);
            $locked->increment('main_balance', $amount);
            $this->broadcastBalance($locked);

            $this->record($locked, 'credit', $amount, $locked->main_balance, 'commission_transfer', null, 'Commission transferred to main balance');

            return $amount;
        });
    }
}
