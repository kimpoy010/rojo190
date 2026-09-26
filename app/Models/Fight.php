<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fight extends Model
{
    /**
     * Statuses a bet (player, counter, or kiosk) can still be placed on —
     * 'open' and 'last_call' both accept bets; 'last_call' is purely a
     * "closes very soon" warning to bettors, not an early cutoff.
     */
    public const BETTABLE_STATUSES = ['open', 'last_call'];

    /**
     * Statuses that count as "still in play" — not yet declared or
     * cancelled. Used everywhere a fight/cockpit is considered active: the
     * event's own currentFight, cockpit-availability checks, the player's
     * fight switcher / PiP list, etc.
     */
    public const IN_PLAY_STATUSES = ['pending', 'open', 'last_call', 'closed'];

    protected $fillable = [
        'event_id',
        'cockpit_id',
        'fight_number',
        'status',
        'draw_enabled',
        'winner',
        'declared_at',
    ];

    protected function casts(): array
    {
        return [
            'draw_enabled' => 'boolean',
            'declared_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function cockpit(): BelongsTo
    {
        return $this->belongsTo(Cockpit::class);
    }

    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    public function isBettable(): bool
    {
        return in_array($this->status, self::BETTABLE_STATUSES, true);
    }
}
