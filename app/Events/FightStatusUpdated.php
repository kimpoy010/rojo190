<?php

namespace App\Events;

use App\Models\Fight;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class FightStatusUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly Fight $fight,
        // True only when this fight was created via the declarator's manual
        // "Start next fight" button (as opposed to the automatic next fight
        // created right after declaring/cancelling another) — the player
        // page's "a new fight has started" popup keys off this so it only
        // fires for that specific case, not every routine advance.
        public readonly bool $manualStart = false,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('fight.'.$this->fight->id),
            new Channel('event.'.$this->fight->event_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'FightStatusUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'fight_id' => $this->fight->id,
            'status' => $this->fight->status,
            'winner' => $this->fight->winner,
            'fight_number' => $this->fight->fight_number,
            'event_id' => $this->fight->event_id,
            'draw_enabled' => (bool) $this->fight->draw_enabled,
            'manual_start' => $this->manualStart,
        ];
    }
}
