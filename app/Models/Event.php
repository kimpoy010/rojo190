<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Event extends Model
{
    protected $fillable = [
        'game_id',
        'cockpit_preset_id',
        'name',
        'arena',
        'date',
        'status',
        'thumbnail_url',
        'draw_enabled',
        'multiplier',
        'bet_limit',
        'label_meron',
        'label_wala',
        'label_draw',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'draw_enabled' => 'boolean',
            'multiplier' => 'decimal:2',
            'bet_limit' => 'decimal:2',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function cockpitPreset(): BelongsTo
    {
        return $this->belongsTo(CockpitPreset::class);
    }

    /**
     * The event's default camera — its preset's first cockpit — used to
     * preview the event (lobby tile, pool-betting page) before any of its
     * fights has its own cockpit assigned by the declarator. Once a fight
     * opens with a cockpit, that fight's own feed takes priority over
     * this everywhere it's used.
     */
    public function primaryStreamUrl(): ?string
    {
        return $this->cockpitPreset?->cockpits->first()?->stream_url;
    }

    public function fights(): HasMany
    {
        return $this->hasMany(Fight::class);
    }

    /**
     * Which fixed-odds tiers this (CombinedSabong) event offers, in
     * display order — superadmin-assigned. Empty for every non-combined
     * event, and for a combined event that hasn't been configured yet
     * (CombinedBettingService::placeBet() falls back to every globally
     * active OddsTier in that case).
     */
    public function oddsTiers(): BelongsToMany
    {
        return $this->belongsToMany(OddsTier::class, 'event_odds_tiers')
            ->withPivot('display_order')
            ->orderBy('event_odds_tiers.display_order');
    }

    /**
     * The fight currently in play (or up next) — mirrors the reference app's
     * "current fight" concept used to route players/declarators to the live round.
     */
    public function currentFight(): HasOne
    {
        return $this->hasOne(Fight::class)
            ->whereIn('status', Fight::IN_PLAY_STATUSES)
            ->latestOfMany('fight_number');
    }

    public function sideLabel(string $side): string
    {
        return match ($side) {
            'meron' => $this->label_meron,
            'wala' => $this->label_wala,
            'draw' => $this->label_draw,
            default => ucfirst($side),
        };
    }

    /**
     * The banner shown on the player events list — this event's own upload,
     * falling back to its game's default so events don't need one set
     * individually. Null means the view should render its placeholder.
     */
    public function displayBannerUrl(): ?string
    {
        return $this->thumbnail_url ?: $this->game?->default_banner_url;
    }
}
