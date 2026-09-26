<?php

namespace App\Events;

use App\Models\RfidTerminal;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Pushed to a teller station screen when a player taps their card on that
 * station's 'identify' reader — the teller then drives everything (deposit,
 * withdrawal) from what's on screen; this event carries just enough to
 * render that screen.
 */
class PlayerIdentified implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly RfidTerminal $terminal,
        public readonly User $player,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('kiosk.'.$this->terminal->token)];
    }

    public function broadcastAs(): string
    {
        return 'PlayerIdentified';
    }

    public function broadcastWith(): array
    {
        return [
            'player_id' => $this->player->id,
            'display_name' => $this->player->displayName(),
            'username' => $this->player->username,
            'wallet_balance' => (float) ($this->player->wallet->main_balance ?? 0),
            'available_balance' => (float) ($this->player->wallet?->availableBalance() ?? 0),
        ];
    }
}
