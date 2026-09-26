<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical ESP32 + Wiegand reader board that has checked in against a
 * terminal. Every board runs identical firmware — it only ever reports its
 * own device_id (WiFi MAC) and a scanned tag — so which physical reader is
 * "MERON" vs "WALA" vs "TOPUP" is entirely a server-side assignment here,
 * not anything baked into the firmware.
 */
class RfidReader extends Model
{
    protected $fillable = [
        'rfid_terminal_id',
        'device_id',
        'role',
        'label',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(RfidTerminal::class, 'rfid_terminal_id');
    }

    public function isAssigned(): bool
    {
        return ! is_null($this->role);
    }
}
