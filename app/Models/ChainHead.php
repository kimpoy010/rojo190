<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChainHead extends Model
{
    protected $fillable = [
        'chain',
        'scope',
        'last_hash',
    ];
}
