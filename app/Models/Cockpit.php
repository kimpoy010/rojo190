<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cockpit extends Model
{
    protected $fillable = [
        'name',
        'stream_url',
    ];

    public function fights(): HasMany
    {
        return $this->hasMany(Fight::class);
    }

    public function cockpitPresets(): BelongsToMany
    {
        return $this->belongsToMany(CockpitPreset::class, 'cockpit_cockpit_preset')
            ->withPivot('position')
            ->orderByPivot('position');
    }
}
