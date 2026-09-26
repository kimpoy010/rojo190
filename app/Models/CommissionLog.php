<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'fight_id', 'agent_id', 'player_id', 'bet_id',
        'side', 'matched_amount', 'amount', 'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'matched_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'credited_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player_id');
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    public function fight(): BelongsTo
    {
        return $this->belongsTo(Fight::class);
    }
}
