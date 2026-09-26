<?php

namespace App\Models;

use App\Models\Concerns\HasHashChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransaction extends Model
{
    use HasHashChain;

    protected $fillable = [
        'user_id',
        'teller_id',
        'teller_shift_id',
        'type',
        'origin',
        'amount',
        'code',
        'status',
        'expires_at',
        'completed_at',
    ];

    /**
     * Only the terms of the request as it was created — status,
     * teller_id, teller_shift_id, and completed_at all get set later by
     * approve()/cancel()/expire() and aren't part of the tamper-evident
     * creation fact (the resulting money movement, once approved, is
     * covered separately by its own WalletTransaction chain entry).
     */
    public function hashChainFields(): array
    {
        return ['user_id', 'type', 'origin', 'amount', 'code', 'expires_at'];
    }

    /**
     * One chain per player — a player can only ever have one pending
     * request at a time (see CashTransactionService::assertNoPendingRequest),
     * so this scope never sees real contention.
     */
    public function hashChainScope(): string
    {
        return 'user:'.$this->user_id;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Route models by the random `code` (as embedded in the QR/scan URL)
     * rather than the numeric id — used wherever a route binds
     * {cashTransaction:code}.
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_id');
    }

    public function tellerShift(): BelongsTo
    {
        return $this->belongsTo(TellerShift::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->isPending() && $this->expires_at->isPast();
    }
}
