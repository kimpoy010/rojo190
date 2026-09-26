<?php

namespace App\Events;

use App\Models\CashTransaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast whenever a cash-in/cash-out request's status changes, so the
 * player's QR page updates live instead of waiting on the polling fallback.
 */
class CashTransactionUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(public readonly CashTransaction $transaction) {}

    public function broadcastOn(): array
    {
        return [new Channel('cash-transaction.'.$this->transaction->code)];
    }

    public function broadcastAs(): string
    {
        return 'CashTransactionUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => $this->transaction->status,
        ];
    }
}
