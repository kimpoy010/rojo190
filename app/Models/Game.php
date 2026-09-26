<?php

namespace App\Models;

use App\Support\GameTheme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'game_name',
        'game_type',
        'display_name',
        'game_status',
        'region',
        'video_enabled',
        'default_banner_url',
        'plasada',
        'plasada_mode',
        'odds_plasada',
        'draw_multiplier',
        'max_draw_bet',
        'min_payout_threshold',
    ];

    protected function casts(): array
    {
        return [
            'video_enabled' => 'boolean',
            'plasada' => 'decimal:2',
            'odds_plasada' => 'decimal:2',
            'draw_multiplier' => 'decimal:2',
            'max_draw_bet' => 'decimal:2',
            'min_payout_threshold' => 'decimal:2',
        ];
    }

    /**
     * A CombinedSabong game — the only game_type value used today. Drives
     * the player/declarator routing branch in PlayerEventController and
     * Superadmin\EventController; every other Game row (game_type null)
     * keeps today's pool-sabong-only behavior.
     */
    public function isCombined(): bool
    {
        return $this->game_type === 'combined';
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
