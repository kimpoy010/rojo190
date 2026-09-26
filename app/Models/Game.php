<?php

namespace App\Models;

use App\Support\GameTheme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'game_name',
        'display_name',
        'game_status',
        'region',
        'video_enabled',
        'default_banner_url',
        'plasada',
        'plasada_mode',
        'draw_multiplier',
        'max_draw_bet',
        'min_payout_threshold',
    ];

    protected function casts(): array
    {
        return [
            'video_enabled' => 'boolean',
            'plasada' => 'decimal:2',
            'draw_multiplier' => 'decimal:2',
            'max_draw_bet' => 'decimal:2',
            'min_payout_threshold' => 'decimal:2',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * The betting labels/colors for this game's region — see GameTheme.
     */
    public function theme(): array
    {
        return GameTheme::for($this->region);
    }
}
