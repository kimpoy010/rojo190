<?php

namespace App\Services;

use App\Models\Bet;
use App\Models\CommissionLog;
use App\Models\Fight;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CommissionService
{
    public function __construct(private WalletService $walletService) {}

    /**
     * Distribute commission up a bet's agent chain. Returns the total amount
     * actually credited across the chain (0.0 if the bettor has no agent).
     *
     * Each agent in the chain earns only the *differential* between their own
     * game-specific rate and their downline's rate — so a Level 1 agent set
     * at 2% and a Level 2 sub-agent set at 1.5% means the sub-agent earns
     * 1.5% and the L1 agent earns only the remaining 0.5%, not another full
     * 2%. This keeps the total commission paid out capped at the top agent's
     * rate regardless of how many levels the chain has.
     */
    public function distributeCommission(Bet $bet, ?float $amount = null): float
    {
        $player = $bet->user;

        if (! $player->agent_id) {
            return 0.0;
        }

        $betAmount = $amount ?? (float) $bet->amount;

        $gameId = Fight::where('fights.id', $bet->fight_id)
            ->join('events', 'events.id', '=', 'fights.event_id')
            ->value('events.game_id');

        if (! $gameId) {
            return 0.0;
        }

        // Phase 1: walk up the hierarchy collecting agent ids (capped depth
        // guards against a corrupted/circular agent_id chain).
        $agentIds = [];
        $agentId = $player->agent_id;
        $depth = 0;
        while ($agentId && $depth < 10) {
            $agentIds[] = $agentId;
            $agentId = User::where('id', $agentId)->value('agent_id');
            $depth++;
        }

        if (empty($agentIds)) {
            return 0.0;
        }

        // Phase 2: load agents + their game-specific rates, then credit the
        // differential up the chain.
        $agents = User::with(['wallet', 'commissionRates'])
            ->whereIn('id', $agentIds)
            ->get()
            ->keyBy('id');

        $downlineRate = 0.00;
        $totalDistributed = 0.0;

        foreach ($agentIds as $id) {
            $current = $agents->get($id);
            if (! $current || ! $current->hasRole('agent')) {
                continue;
            }

            $agentRate = (float) (
                $current->commissionRates->firstWhere('game_id', $gameId)?->commission_rate ?? 0.00
            );
            $differential = round($agentRate - $downlineRate, 4);

            if ($differential > 0.001 && $current->wallet) {
                $commission = round($betAmount * $differential / 100, 2);

                $this->walletService->creditCommission(
                    $current->wallet,
                    $commission,
                    'commission',
                    $bet->id,
                    "Commission from Bet #{$bet->id} (Fight #{$bet->fight_id})"
                );

                CommissionLog::create([
                    'fight_id' => $bet->fight_id,
                    'agent_id' => $current->id,
                    'player_id' => $player->id,
                    'bet_id' => $bet->id,
                    'side' => $bet->side,
                    'matched_amount' => $betAmount,
                    'amount' => $commission,
                    'credited_at' => now(),
                ]);

                Log::info('commission.credited', [
                    'agent_id' => $current->id,
                    'game_id' => $gameId,
                    'bet_id' => $bet->id,
                    'amount' => $commission,
                ]);

                $totalDistributed += $commission;
            }

            $downlineRate = $agentRate;
        }

        return round($totalDistributed, 2);
    }
}
