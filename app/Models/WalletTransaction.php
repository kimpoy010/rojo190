<?php

namespace App\Models;

use App\Models\Concerns\HasHashChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use HasHashChain;

    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
    ];

    /**
     * Every field here is set once at creation and never updated
     * afterward — this table is a pure append-only ledger.
     */
    public function hashChainFields(): array
    {
        return ['wallet_id', 'type', 'amount', 'balance_after', 'reference_type', 'reference_id', 'description'];
    }

    /**
     * One chain per wallet — every write here already locks the wallet
     * row first (see WalletService), so this reuses that same natural
     * serialization point instead of introducing a system-wide one.
     */
    public function hashChainScope(): string
    {
        return 'wallet:'.$this->wallet_id;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
