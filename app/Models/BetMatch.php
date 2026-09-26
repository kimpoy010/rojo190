<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BetMatch extends Model
{
    protected $fillable = ['meron_bet_id', 'wala_bet_id', 'odds_tier_id', 'matched_amount'];

    protected function casts(): array
    {
        return [
            'matched_amount' => 'decimal:2',
        ];
    }

    public function meronBet(): BelongsTo
    {
        return $this->belongsTo(Bet::class, 'meron_bet_id');
    }

    public function walaBet(): BelongsTo
    {
        return $this->belongsTo(Bet::class, 'wala_bet_id');
    }

    public function oddsTier(): BelongsTo
    {
        return $this->belongsTo(OddsTier::class);
    }
}
