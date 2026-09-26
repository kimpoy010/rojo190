<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCommissionRate extends Model
{
    protected $fillable = ['agent_id', 'game_id', 'commission_rate'];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Recursively cap commission rates for all descendants of $agent so that
     * no downline holds a rate higher than $ceiling for $gameId. Keeps the
     * differential-rate model solvent whenever a rate is lowered upstream.
     * Iterative BFS to avoid stack overflow on deep hierarchies.
     */
    public static function cascadeDown(User $agent, int $gameId, float $ceiling): void
    {
        $queue = [$agent->id];

        while (! empty($queue)) {
            $parentId = array_shift($queue);
            $downlines = User::where('agent_id', $parentId)->where('id', '!=', $agent->id)->get(['id']);

            foreach ($downlines as $downline) {
                $current = (float) (self::where('agent_id', $downline->id)
                    ->where('game_id', $gameId)
                    ->value('commission_rate') ?? 0);

                if ($current > $ceiling) {
                    self::updateOrCreate(
                        ['agent_id' => $downline->id, 'game_id' => $gameId],
                        ['commission_rate' => $ceiling]
                    );
                }

                $queue[] = $downline->id;
            }
        }
    }
}
