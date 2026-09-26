<?php

namespace App\Events;

use App\Models\RfidTerminal;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to a kiosk's screen after every card tap (success or failure) so
 * the player gets on-screen confirmation of what just happened — the
 * physical readers have no display of their own.
 */
class KioskScanResult implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly RfidTerminal $terminal,
        public readonly bool $success,
        public readonly string $message,
        public readonly array $details = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('kiosk.'.$this->terminal->token)];
    }

    public function broadcastAs(): string
    {
        return 'KioskScanResult';
    }

    public function broadcastWith(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
