<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A physical self-service betting/top-up kiosk: two or more ESP32+Wiegand
 * RFID readers (see RfidReader — MERON/WALA bets, TOPUP requests) that
 * share this terminal's bearer token to authenticate. `token` doubles as
 * the unguessable segment of the kiosk's public screen URL.
 *
 * A terminal is permanent hardware, not tied to any one event — it's set
 * up once and just keeps working across every future card. It always
 * follows whichever event is currently live (see activeEvent()), exactly
 * like the teller ticket-writer (TicketController::activeEvent()).
 */
class RfidTerminal extends Model
{
    protected $fillable = [
        'name',
        'token',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function readers(): HasMany
    {
        return $this->hasMany(RfidReader::class);
    }

    public static function generateToken(): string
    {
        return Str::upper(Str::random(32));
    }

    /**
     * The single event currently live — every terminal serves whatever
     * that is, with no per-terminal configuration needed.
     */
    public function activeEvent(): ?Event
    {
        return Event::where('status', 'live')->latest('id')->first();
    }

    /**
     * The fight this terminal is currently allowed to bet on — the live
     * event's single open fight, if any.
     */
    public function openFight(): ?Fight
    {
        return $this->activeEvent()?->fights()->whereIn('status', Fight::BETTABLE_STATUSES)->latest('fight_number')->first();
    }
}
