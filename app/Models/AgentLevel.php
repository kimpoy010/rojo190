<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentLevel extends Model
{
    protected $fillable = ['level', 'label', 'commission_rate'];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
        ];
    }

    public function agents(): HasMany
    {
        return $this->hasMany(User::class, 'agent_level_id');
    }
}
