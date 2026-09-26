<?php

namespace App\Services;

use App\Events\CashTransactionUpdated;
use App\Models\CashTransaction;
use App\Models\TellerShift;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Broadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CashTransactionService
{
    public const EXPIRES_IN_MINUTES = 15;

    public function __construct(private WalletService $walletService) {}

    /**
     * Start a deposit ("cash in") request. Nothing is credited yet — that
     * only happens once a teller scans the QR (or looks up an RFID-kiosk
     * request), receives payment, and approves it.
     */
    public function createDeposit(User $player, float $amount, string $origin = 'teller'): CashTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('Deposit amount must be positive.'));
        }

        $this->assertNoPendingRequest($player);

        $transaction = CashTransaction::create([
            'user_id' => $player->id,
            'type' => 'deposit',
            'origin' => $origin,
            'amount' => $amount,
            'code' => $this->generateCode(),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
        ]);

        AuditLogger::log(
            action: 'cash.deposit_requested',
            description: __(':name requested a :amount deposit.', ['name' => $player->displayName(), 'amount' => $amount]),
            target: $transaction,
            actor: $player,
        );

        return $transaction;
    }

    /**
     * Start a withdrawal ("cash out") request for the player's full
     * available balance. The amount is snapshotted and immediately
     * reserved (held out of main_balance's *available* portion) so it
     * can't be bet away or double-withdrawn while the request is pending —
     * but main_balance itself isn't touched until a teller approves it.
     */
    public function createWithdrawal(User $player): CashTransaction
    {
        $this->assertNoPendingRequest($player);

        $wallet = $player->wallet;
        $amount = $wallet->availableBalance();

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('No available balance to withdraw.'));
        }

        $transaction = DB::transaction(function () use ($player, $wallet, $amount) {
            $this->walletService->reserveWithdrawal($wallet, $amount);

            return CashTransaction::create([
                'user_id' => $player->id,
                'type' => 'withdrawal',
                'amount' => $amount,
                'code' => $this->generateCode(),
                'status' => 'pending',
                'expires_at' => now()->addMinutes(self::EXPIRES_IN_MINUTES),
            ]);
        });

        AuditLogger::log(
            action: 'cash.withdrawal_requested',
            description: __(':name requested a :amount withdrawal.', ['name' => $player->displayName(), 'amount' => $amount]),
            target: $transaction,
            actor: $player,
        );

        return $transaction;
    }

    /**
     * Credit a player's wallet immediately — one step, no pending/approve
     * split. For a teller-initiated deposit where the teller is physically
     * handing the transaction right now (e.g. an RFID-identified player at
     * the counter): the teller present *is* the approver, so there's
     * nothing to separately approve later. Still recorded as a completed
     * CashTransaction against the teller's shift, exactly like an approved
     * QR-flow deposit, so shift reconciliation and history look identical
     * either way.
     */
    public function instantDeposit(User $player, float $amount, User $teller, TellerShift $shift, string $origin = 'teller'): CashTransaction
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('Deposit amount must be positive.'));
        }

        $this->assertNoPendingRequest($player);

        return DB::transaction(function () use ($player, $amount, $teller, $shift, $origin) {
            $this->walletService->credit(
                $player->wallet,
                $amount,
                'deposit',
                null,
                "Cash deposit via teller {$teller->displayName()}"
            );

            $transaction = CashTransaction::create([
                'user_id' => $player->id,
                'type' => 'deposit',
                'origin' => $origin,
                'amount' => $amount,
                'code' => $this->generateCode(),
                'status' => 'completed',
                'teller_id' => $teller->id,
                'teller_shift_id' => $shift->id,
                'expires_at' => now(),
                'completed_at' => now(),
            ]);

            DB::afterCommit(fn () => Broadcaster::send(new CashTransactionUpdated($transaction)));

            return $transaction;
        });
    }

    /**
     * Withdraw a player's full available balance immediately — one step,
     * same reasoning as instantDeposit(): the teller handing over the cash
     * right now is the approval.
     */
    public function instantWithdrawal(User $player, User $teller, TellerShift $shift, string $origin = 'teller'): CashTransaction
    {
        $this->assertNoPendingRequest($player);

        $wallet = $player->wallet;
        $amount = $wallet->availableBalance();

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('No available balance to withdraw.'));
        }

        return DB::transaction(function () use ($player, $wallet, $amount, $teller, $shift, $origin) {
            // Reserve-then-complete in the same transaction nets out to a
            // plain immediate debit, while reusing the existing balance
            // check and wallet bookkeeping instead of duplicating it.
            $this->walletService->reserveWithdrawal($wallet, $amount);
            $this->walletService->completeWithdrawal(
                $wallet,
                $amount,
                null,
                "Cash withdrawal via teller {$teller->displayName()}"
            );

            $transaction = CashTransaction::create([
                'user_id' => $player->id,
                'type' => 'withdrawal',
                'origin' => $origin,
                'amount' => $amount,
                'code' => $this->generateCode(),
                'status' => 'completed',
                'teller_id' => $teller->id,
                'teller_shift_id' => $shift->id,
                'expires_at' => now(),
                'completed_at' => now(),
            ]);

            DB::afterCommit(fn () => Broadcaster::send(new CashTransactionUpdated($transaction)));

            return $transaction;
        });
    }

    /**
     * Cancel a pending request — callable by the player who owns it (changed
     * their mind) or the teller who scanned it (invalid/no-show). Releases a
     * withdrawal's reservation without ever touching main_balance.
     */
    public function cancel(CashTransaction $transaction): CashTransaction
    {
        return DB::transaction(function () use ($transaction) {
            $locked = CashTransaction::lockForUpdate()->findOrFail($transaction->id);

            if (! $locked->isPending()) {
                throw new \InvalidArgumentException(__('Only a pending request can be cancelled.'));
            }

            if ($locked->type === 'withdrawal') {
                $this->walletService->releaseWithdrawalReservation($locked->user->wallet, (float) $locked->amount);
            }

            $locked->update(['status' => 'cancelled']);
            $fresh = $locked->fresh();

            AuditLogger::log(
                action: 'cash.request_cancelled',
                description: __('Cancelled a :type request for :amount.', ['type' => $fresh->type, 'amount' => $fresh->amount]),
                target: $fresh,
            );

            DB::afterCommit(fn () => Broadcaster::send(new CashTransactionUpdated($fresh)));

            return $fresh;
        });
    }

    /**
     * Approve a pending request at the teller's counter: credits the
     * player's wallet for a deposit, or actually debits it (releasing the
     * reservation) for a withdrawal. When the teller has an open POS shift,
     * pass it so the cash movement counts toward that shift's end-of-shift
     * reconciliation.
     */
    public function approve(CashTransaction $transaction, User $teller, ?TellerShift $shift = null): CashTransaction
    {
        return DB::transaction(function () use ($transaction, $teller, $shift) {
            $locked = CashTransaction::lockForUpdate()->findOrFail($transaction->id);

            if ($locked->isExpired()) {
                $this->expire($locked);
                throw new \InvalidArgumentException(__('This request has expired.'));
            }

            if (! $locked->isPending()) {
                throw new \InvalidArgumentException(__('This request has already been processed.'));
            }

            $wallet = $locked->user->wallet;

            if ($locked->type === 'deposit') {
                $this->walletService->credit(
                    $wallet,
                    (float) $locked->amount,
                    'deposit',
                    $locked->id,
                    "Cash deposit via teller {$teller->displayName()}"
                );
            } else {
                $this->walletService->completeWithdrawal(
                    $wallet,
                    (float) $locked->amount,
                    $locked->id,
                    "Cash withdrawal via teller {$teller->displayName()}"
                );
            }

            $locked->update([
                'status' => 'completed',
                'teller_id' => $teller->id,
                'teller_shift_id' => $shift?->id,
                'completed_at' => now(),
            ]);

            DB::afterCommit(fn () => Broadcaster::send(new CashTransactionUpdated($locked->fresh())));

            return $locked->fresh();
        });
    }

    /**
     * Lazily mark a stale pending request expired (called whenever one is
     * looked up past its expiry) and release any withdrawal reservation.
     */
    public function expire(CashTransaction $transaction): CashTransaction
    {
        return DB::transaction(function () use ($transaction) {
            $locked = CashTransaction::lockForUpdate()->findOrFail($transaction->id);

            if (! $locked->isPending() || ! $locked->isExpired()) {
                return $locked;
            }

            if ($locked->type === 'withdrawal') {
                $this->walletService->releaseWithdrawalReservation($locked->user->wallet, (float) $locked->amount);
            }

            $locked->update(['status' => 'expired']);
            $fresh = $locked->fresh();

            AuditLogger::log(
                action: 'cash.request_expired',
                description: __('A :type request for :amount expired unapproved.', ['type' => $fresh->type, 'amount' => $fresh->amount]),
                target: $fresh,
                actor: $fresh->user,
            );

            DB::afterCommit(fn () => Broadcaster::send(new CashTransactionUpdated($fresh)));

            return $fresh;
        });
    }

    private function assertNoPendingRequest(User $player): void
    {
        $existing = CashTransaction::where('user_id', $player->id)->where('status', 'pending')->first();

        if (! $existing) {
            return;
        }

        if ($existing->isExpired()) {
            $this->expire($existing);

            return;
        }

        throw new \InvalidArgumentException(__('You already have a pending cash request. Cancel it before starting a new one.'));
    }

    private function generateCode(): string
    {
        do {
            $code = Str::upper(Str::random(12));
        } while (CashTransaction::where('code', $code)->exists());

        return $code;
    }
}
