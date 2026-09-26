<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'main_balance',
        'commission_balance',
        'pending_withdrawal',
        'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'main_balance' => 'decimal:2',
            'commission_balance' => 'decimal:2',
            'pending_withdrawal' => 'decimal:2',
            'is_locked' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Main balance minus any amount reserved by a pending withdrawal request —
     * the actual amount a player can bet or request a new withdrawal for.
     */
    public function availableBalance(): float
    {
        return (float) $this->main_balance - (float) $this->pending_withdrawal;
    }
}
