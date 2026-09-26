<?php

namespace App\Events;

use App\Models\Wallet;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast whenever a wallet's balance changes (a bet placed, a payout
 * credited, a withdrawal completed, commission transferred, ...) so a
 * player's balance on screen updates live instead of waiting for their
 * next page load. Private per-user channel — a balance isn't something
 * any other viewer should be able to watch.
 *
 * Sent inline (ShouldBroadcastNow), same as BetPoolUpdated — a payout
 * needs to reach the player fast, and the `database` queue driver used
 * for everything else in this app polls (see deploy/supervisor's
 * --sleep=1), which alone could add up to a full second of latency before
 * a worker even picks the job up. This was queued in an earlier version
 * specifically to keep a bet-placement request from holding its PHP-FPM
 * worker for the Reverb round-trip under load; that tradeoff is reversed
 * now that low-latency payout/balance reflection is the actual
 * requirement — a slightly longer bet-placement response is preferable to
 * a queue-polling delay on the number a player is staring at. Fired from
 * DB::afterCommit() (see WalletService::broadcastBalance()), so it still
 * only ever sends once the balance change is truly final.
 */
class WalletBalanceUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public readonly Wallet $wallet) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('wallet.'.$this->wallet->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'WalletBalanceUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'main_balance' => (float) $this->wallet->main_balance,
            'commission_balance' => (float) $this->wallet->commission_balance,
        ];
    }
}
