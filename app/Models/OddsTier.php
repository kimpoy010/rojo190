<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OddsTier extends Model
{
    protected $fillable = ['label', 'meron_ratio', 'wala_ratio', 'is_active'];

    protected function casts(): array
    {
        return [
            'meron_ratio' => 'decimal:2',
            'wala_ratio' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
