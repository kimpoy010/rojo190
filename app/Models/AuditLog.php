<?php

namespace App\Models;

use App\Models\Concerns\HasHashChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasHashChain;

    const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id',
        'actor_name',
        'action',
        'target_type',
        'target_id',
        'target_label',
        'description',
        'changes',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * Every field here is set once at creation and never updated
     * afterward — this table is a pure append-only log.
     */
    public function hashChainFields(): array
    {
        return ['actor_user_id', 'actor_name', 'action', 'target_type', 'target_id', 'target_label', 'description', 'changes', 'ip_address'];
    }

    /**
     * One chain per actor — mirrors the financial ledger's per-wallet/
     * per-user chains, so unrelated users' concurrent actions never
     * contend on the same lock. A null actor (a console command, a
     * scheduled job) gets its own shared 'system' chain.
     */
    public function hashChainScope(): string
    {
        return $this->actor_user_id ? 'user:'.$this->actor_user_id : 'system';
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
