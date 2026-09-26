<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, curated group of Cockpits (e.g. "Araneta — 3 Ring Setup"),
 * assigned once to an Event so staff pick a preset instead of typing a
 * raw stream URL per event. Declarators still assign a specific Cockpit
 * per fight independently (see Declarator\FightController) — a preset
 * only decides which cockpits an event is associated with, not which one
 * any given fight uses.
 */
class CockpitPreset extends Model
{
    protected $fillable = [
        'name',
    ];

    public function cockpits(): BelongsToMany
    {
        return $this->belongsToMany(Cockpit::class, 'cockpit_cockpit_preset')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
