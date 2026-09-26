<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class BetPoolUpdated implements ShouldBroadcastNow
{
    use SerializesModels;

    public function __construct(
        public readonly int $fightId,
        public readonly float $meronPool,
        public readonly float $walaPool,
        public readonly float $drawPool = 0.00,
        public readonly float $meronPayout = 0.00,
        public readonly float $walaPayout = 0.00,
        public readonly array $latestBet = [],
        // Pre-rounded (2 decimal) percent versions of the ratios above,
        // straight from PoolPayoutCalculator — see its own comment for why
        // these aren't just round($meronPayout * 100, 2) applied
        // independently on each side (it breaks the balancer's guaranteed
        // total).
        public readonly ?float $meronPayoutPct = null,
        public readonly ?float $walaPayoutPct = null,
        // CombinedSabong only: per-odds-tier totals, keyed by odds_tier_id
        // -> ['meron' => float, 'wala' => float]. Always empty for every
        // other game, which never passes this argument at all.
        public readonly array $tierTotals = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('fight.'.$this->fightId)];
    }

    public function broadcastAs(): string
    {
        return 'BetPoolUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'fight_id' => $this->fightId,
            'meron_pool' => $this->meronPool,
            'wala_pool' => $this->walaPool,
            'draw_pool' => $this->drawPool,
            'meron_payout' => $this->meronPayout,
            'wala_payout' => $this->walaPayout,
            'meron_payout_pct' => $this->meronPayoutPct,
            'wala_payout_pct' => $this->walaPayoutPct,
            'latest_bet' => $this->latestBet,
            'tier_totals' => $this->tierTotals,
        ];
    }
}
