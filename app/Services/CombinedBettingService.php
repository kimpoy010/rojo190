<?php

namespace App\Services;

use App\Events\BetPoolUpdated;
use App\Models\Bet;
use App\Models\BetMatch;
use App\Models\Fight;
use App\Models\Game;
use App\Models\OddsTier;
use App\Models\User;
use App\Support\Broadcaster;
use App\Support\PoolPayoutCalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Places and settles bets for a `combined-sabong` fight — one fight that
 * accepts both pool (pari-mutuel) bets and fixed-odds (matched) bets at
 * once. A standalone service, structurally parallel to BettingService
 * rather than built on top of it, so nothing about Pool Sabong's own
 * betting/settlement is ever touched by this game.
 *
 * A Bet row's `odds_tier_id` is the discriminator: set -> a fixed-odds
 * bet, matched against the opposite side at that tier; null (side
 * meron/wala) -> a pool bet, pooled directly with no matching. A `$mode`
 * parameter ('pool' or 'odds') tells placeBet() which the caller intends
 * — needed because a `draw` bet always carries `odds_tier_id = null`
 * regardless of which panel placed it, so the column alone can't
 * disambiguate intent for validation.
 */
class CombinedBettingService
{
    public function __construct(
        private WalletService $walletService,
        private CommissionService $commissionService,
    ) {}

    public function placeBet(User $user, Fight $fight, ?OddsTier $oddsTier, string $side, string $mode, float $amount): Bet
    {
        if (! in_array($mode, ['pool', 'odds'], true)) {
            throw new \InvalidArgumentException(__('Invalid betting mode.'));
        }

        if (! $fight->isBettable()) {
            throw new \InvalidArgumentException(__('Bets can only be placed on open fights.'));
        }

        if ($fight->event->status !== 'live') {
            throw new \InvalidArgumentException(__('Bets can only be placed on live events.'));
        }

        $game = $fight->event->game;
        $isPool = $mode === 'pool';

        if ($side === 'draw') {
            if (! $fight->draw_enabled) {
                throw new \InvalidArgumentException(__('Draw betting is not enabled for this fight.'));
            }
            $oddsTier = null;
        } elseif ($isPool) {
            $oddsTier = null;
        } else {
            if (! $oddsTier) {
                throw new \InvalidArgumentException(__('A valid active odds tier is required for meron/wala bets.'));
            }

            $eventTiers = $fight->event->oddsTiers;
            $tierAllowed = $eventTiers->isNotEmpty()
                ? $eventTiers->contains('id', $oddsTier->id)
                : $oddsTier->is_active;

            if (! $tierAllowed) {
                throw new \InvalidArgumentException(__('A valid active odds tier is required for meron/wala bets.'));
            }
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('Bet amount must be positive.'));
        }

        // Cross-betting guard: a player can't bet both sides of the same
        // odds tier in the same fight — only meaningful for odds-mode
        // meron/wala bets (pool bets carry no odds_tier_id at all).
        if ($oddsTier && in_array($side, ['meron', 'wala'], true)) {
            $oppositeSide = $side === 'meron' ? 'wala' : 'meron';
            $crossBetExists = Bet::where('fight_id', $fight->id)
                ->where('user_id', $user->id)
                ->where('odds_tier_id', $oddsTier->id)
                ->where('side', $oppositeSide)
                ->whereNotIn('status', ['refunded', 'cancelled'])
                ->exists();

            if ($crossBetExists) {
                throw new \InvalidArgumentException(__('You already have a :side bet on this odds tier. Cross-betting on the same tier is not allowed.', ['side' => $oppositeSide]));
            }
        }

        $matchedOpponents = [];

        $bet = DB::transaction(function () use ($user, $fight, $oddsTier, $side, $amount, $isPool, $game, &$matchedOpponents) {
            $wallet = $user->wallet()->lockForUpdate()->first();

            // Re-read fight with lock to guard against concurrent close. This
            // same lock also serializes concurrent draw bets on this fight,
            // making the pool-wide draw cap check below race-safe.
            $freshFight = Fight::lockForUpdate()->find($fight->id);
            if (! $freshFight->isBettable()) {
                throw new \InvalidArgumentException(__('Bets can only be placed on open fights.'));
            }

            if ($side === 'draw' && $game && $game->max_draw_bet > 0) {
                $existingDraw = Bet::where('fight_id', $freshFight->id)
                    ->where('side', 'draw')
                    ->whereNotIn('status', ['refunded', 'cancelled'])
                    ->sum('amount');
                $remaining = (float) $game->max_draw_bet - (float) $existingDraw;
                if ($amount > $remaining) {
                    throw new \InvalidArgumentException($remaining > 0
                        ? __(':remaining remaining in the draw pool (limit :limit).', ['remaining' => '$'.number_format($remaining, 2), 'limit' => '$'.number_format((float) $game->max_draw_bet, 0)])
                        : __('The draw pool limit of :limit has been reached.', ['limit' => '$'.number_format((float) $game->max_draw_bet, 0)]));
                }
            }

            $bet = Bet::create([
                'user_id' => $user->id,
                'fight_id' => $freshFight->id,
                'odds_tier_id' => $oddsTier?->id,
                'side' => $side,
                'amount' => $amount,
                // Pool bets (and draw bets, either mode) go straight to
                // matched — no pairing needed. Only odds-mode meron/wala
                // bets go through order-matching below.
                'matched_amount' => ($isPool || $side === 'draw') ? $amount : 0.00,
                'unmatched_amount' => ($isPool || $side === 'draw') ? 0.00 : $amount,
                'status' => ($isPool || $side === 'draw') ? 'matched' : 'pending',
            ]);

            $this->walletService->debitBet(
                $wallet,
                $amount,
                'bet',
                $bet->id,
                "Bet on {$side} for Fight #{$fight->fight_number}"
            );

            if (! $isPool && $side !== 'draw') {
                $matchedOpponents = $this->matchBet($bet);
                // Unmatched amounts are held until betting closes and
                // refunded in bulk via refundUnmatched().
            }

            return $bet;
        });

        Cache::forget("combined.fight.{$fight->id}.totals");

        $this->broadcastPoolUpdate($fight, $game, [
            'name' => $user->displayName(),
            'side' => $bet->side,
            'mode' => $mode,
            'amount' => number_format((float) $bet->matched_amount + (float) $bet->unmatched_amount),
            'time' => $bet->created_at->format('H:i:s'),
        ]);

        Log::info('combined_bet.placed', [
            'user_id' => $user->id,
            'fight_id' => $fight->id,
            'side' => $side,
            'mode' => $mode,
            'amount' => $amount,
        ]);

        return $bet;
    }

    /**
     * Order-match an odds-mode bet against pending/partially-matched
     * opposite-side bets on the same tier, oldest first. Returns the
     * opposing bets that received a new match, for the caller to notify.
     *
     * @return array<int, array{id: int, user_id: int}>
     */
    private function matchBet(Bet $bet): array
    {
        if ((float) $bet->unmatched_amount <= 0) {
            return [];
        }

        $oppositeSide = $bet->side === 'meron' ? 'wala' : 'meron';
        $tier = $bet->oddsTier;
        $matchedBetIds = [];

        $opposingBets = Bet::where('fight_id', $bet->fight_id)
            ->where('odds_tier_id', $bet->odds_tier_id)
            ->where('side', $oppositeSide)
            ->whereIn('status', ['pending', 'partially_matched'])
            ->where('unmatched_amount', '>', 0)
            ->where('user_id', '!=', $bet->user_id)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($opposingBets as $opposing) {
            if ((float) $bet->unmatched_amount < 0.001) {
                break;
            }

            if ($bet->side === 'meron') {
                $walaCapacity = round((float) $opposing->unmatched_amount * ((float) $tier->meron_ratio / (float) $tier->wala_ratio), 2);
                $matchMeron = min(round((float) $bet->unmatched_amount, 2), $walaCapacity);
                $matchWala = round($matchMeron * ((float) $tier->wala_ratio / (float) $tier->meron_ratio), 2);
                $meronBet = $bet;
                $walaBet = $opposing;
            } else {
                $meronCapacity = round((float) $opposing->unmatched_amount * ((float) $tier->wala_ratio / (float) $tier->meron_ratio), 2);
                $matchWala = min(round((float) $bet->unmatched_amount, 2), $meronCapacity);
                $matchMeron = round($matchWala * ((float) $tier->meron_ratio / (float) $tier->wala_ratio), 2);
                $meronBet = $opposing;
                $walaBet = $bet;
            }

            if ($matchMeron < 0.001) {
                continue;
            }

            BetMatch::create([
                'meron_bet_id' => $meronBet->id,
                'wala_bet_id' => $walaBet->id,
                'odds_tier_id' => $tier->id,
                'matched_amount' => $matchMeron,
            ]);

            $meronBet->increment('matched_amount', $matchMeron);
            $meronBet->decrement('unmatched_amount', $matchMeron);
            $walaBet->increment('matched_amount', $matchWala);
            $walaBet->decrement('unmatched_amount', $matchWala);

            $this->updateBetStatus($meronBet->fresh());
            $this->updateBetStatus($walaBet->fresh());

            $matchedBetIds[] = ['id' => $opposing->id, 'user_id' => $opposing->user_id];
        }

        return $matchedBetIds;
    }

    private function updateBetStatus(Bet $bet): void
    {
        if ((float) $bet->unmatched_amount < 0.001) {
            $bet->update(['status' => 'matched']);
        } elseif ((float) $bet->matched_amount > 0) {
            $bet->update(['status' => 'partially_matched']);
        } else {
            $bet->update(['status' => 'pending']);
        }
    }

    private function broadcastPoolUpdate(Fight $fight, ?Game $game, array $latestBet = []): void
    {
        $pools = Bet::where('fight_id', $fight->id)
            ->whereNotIn('status', ['refunded', 'cancelled'])
            ->selectRaw("
                SUM(CASE WHEN side='meron' THEN matched_amount + unmatched_amount ELSE 0 END) as meron,
                SUM(CASE WHEN side='wala'  THEN matched_amount + unmatched_amount ELSE 0 END) as wala,
                SUM(CASE WHEN side='draw'  THEN matched_amount + unmatched_amount ELSE 0 END) as draw
            ")
            ->first();

        $poolTotals = Bet::where('fight_id', $fight->id)
            ->whereNull('odds_tier_id')
            ->whereIn('side', ['meron', 'wala'])
            ->whereNotIn('status', ['refunded', 'cancelled'])
            ->selectRaw("
                SUM(CASE WHEN side='meron' THEN amount ELSE 0 END) as meron,
                SUM(CASE WHEN side='wala'  THEN amount ELSE 0 END) as wala
            ")
            ->first();

        $plasada = $game ? (float) $game->plasada : 5.00;
        // Fixed 'total_pool' for the pool subset — see settleBets()'s
        // own comment on why this isn't Game::plasada_mode.
        $payouts = PoolPayoutCalculator::calculate((float) $poolTotals->meron, (float) $poolTotals->wala, $plasada, 'total_pool');

        $tierTotals = $this->tierTotalsWithAvailability($fight);

        Broadcaster::send(new BetPoolUpdated(
            $fight->id,
            (float) $pools->meron,
            (float) $pools->wala,
            (float) $pools->draw,
            $payouts['meron'],
            $payouts['wala'],
            $latestBet,
            $payouts['meron_pct'],
            $payouts['wala_pct'],
            $tierTotals,
        ));
    }

    /**
     * Per-tier totals plus how much stake is available to match
     * immediately on the OPPOSITE side right now — e.g. at a 10-9 tier
     * with $100 unmatched on meron, a wala bettor sees "Avail: $90"
     * (100 * 9/10), the same capacity conversion matchBet() itself uses
     * to decide how much of a new bet actually matches.
     *
     * @return array<int, array{meron: float, wala: float, meron_avail: float, wala_avail: float}>
     */
    private function tierTotalsWithAvailability(Fight $fight): array
    {
        $rows = Bet::where('bets.fight_id', $fight->id)
            ->whereNotNull('odds_tier_id')
            ->whereIn('side', ['meron', 'wala'])
            ->whereNotIn('status', ['refunded', 'cancelled'])
            ->join('odds_tiers', 'odds_tiers.id', '=', 'bets.odds_tier_id')
            ->selectRaw('bets.odds_tier_id, bets.side, odds_tiers.meron_ratio, odds_tiers.wala_ratio, SUM(bets.matched_amount + bets.unmatched_amount) as total, SUM(bets.unmatched_amount) as unmatched')
            ->groupBy('bets.odds_tier_id', 'bets.side', 'odds_tiers.meron_ratio', 'odds_tiers.wala_ratio')
            ->get()
            ->groupBy('odds_tier_id');

        return $rows->map(function ($tierRows) {
            $meronRow = $tierRows->firstWhere('side', 'meron');
            $walaRow = $tierRows->firstWhere('side', 'wala');
            $meronRatio = (float) ($meronRow?->meron_ratio ?? $walaRow?->meron_ratio ?? 1);
            $walaRatio = (float) ($meronRow?->wala_ratio ?? $walaRow?->wala_ratio ?? 1);
            $meronUnmatched = (float) ($meronRow?->unmatched ?? 0);
            $walaUnmatched = (float) ($walaRow?->unmatched ?? 0);

            return [
                'meron' => (float) ($meronRow?->total ?? 0),
                'wala' => (float) ($walaRow?->total ?? 0),
                'meron_avail' => $walaRatio > 0 ? round($walaUnmatched * ($meronRatio / $walaRatio), 2) : 0.0,
                'wala_avail' => $meronRatio > 0 ? round($meronUnmatched * ($walaRatio / $meronRatio), 2) : 0.0,
            ];
        })->toArray();
    }

    /**
     * Settle a declared combined fight: the pool subset (odds_tier_id
     * null, side meron/wala), the odds subset (odds_tier_id set), and the
     * shared draw subset (side='draw'), each with its own idempotency
     * guard so any combination of subsets present on the fight settles
     * safely — including on a repeated call.
     */
    public function settleBets(Fight $fight, string $winner): void
    {
        if (! in_array($winner, ['meron', 'wala', 'draw'], true)) {
            throw new \InvalidArgumentException(__('Invalid winner value: :winner. Must be meron, wala, or draw.', ['winner' => $winner]));
        }

        DB::transaction(function () use ($fight, $winner) {
            Fight::lockForUpdate()->findOrFail($fight->id);

            $game = $fight->event->game;
            if (! $game) {
                throw new \InvalidArgumentException("Fight #{$fight->id} has no associated game — cannot settle.");
            }

            if (! Bet::where('fight_id', $fight->id)->whereIn('status', ['pending', 'partially_matched', 'matched'])->exists()) {
                return; // already settled
            }

            $poolPlasada = (float) ($game->plasada ?? 5.00);
            $oddsPlasada = (float) ($game->odds_plasada ?? $game->plasada ?? 5.00);

            // Fixed by design, not superadmin-configurable for this game:
            // the pool/totalizer subset always rakes the combined pool
            // ('total_pool'), the odds/fixed subset always rakes only the
            // losing side ('losing_side') — see Game::plasada_mode's own
            // doc comment for what each mode means. Game::plasada_mode
            // itself is unused here; it only ever applied to a whole
            // fight at once, which doesn't fit a game with two subsets.
            $this->settlePoolSubset($fight, $winner, $poolPlasada, 'total_pool');
            $this->settleOddsSubset($fight, $winner, $oddsPlasada, 'losing_side');
            $this->settleDrawSubset($fight, $winner, $game);
        });
    }

    /**
     * Pool subset: pari-mutuel bets (odds_tier_id null, side meron/wala).
     * Voiding on a too-one-sided pool refunds ONLY this subset — the odds
     * subset on the same fight settles normally regardless.
     */
    private function settlePoolSubset(Fight $fight, string $winner, float $plasada, string $plasadaMode): void
    {
        $poolBetsQuery = fn () => Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')->whereIn('side', ['meron', 'wala']);

        if (! $poolBetsQuery()->where('status', 'matched')->exists()) {
            return;
        }

        if ($winner === 'draw') {
            $poolBetsQuery()->with('user.wallet')->lockForUpdate()->get()->each(function (Bet $bet) use ($fight) {
                $this->walletService->creditBet(
                    $bet->user->wallet,
                    $bet->amount,
                    'refund',
                    $bet->id,
                    "Draw declared — refund Fight #{$fight->fight_number}"
                );
                $bet->update(['status' => 'settled', 'payout' => $bet->amount]);
            });

            return;
        }

        $losingSide = $winner === 'meron' ? 'wala' : 'meron';
        $meronPool = (float) Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')->where('side', 'meron')->sum('amount');
        $walaPool = (float) Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')->where('side', 'wala')->sum('amount');

        $poolPayouts = PoolPayoutCalculator::calculate($meronPool, $walaPool, $plasada, $plasadaMode);
        $payoutRatio = $winner === 'meron' ? $poolPayouts['meron'] : $poolPayouts['wala'];

        // Pool subset so one-sided that winners would receive less than
        // they bet. Void just this subset: refund everyone in it, no
        // rake, no commission — the odds subset is unaffected.
        if ($payoutRatio < 1.0) {
            $poolBetsQuery()->with('user.wallet')->lockForUpdate()->get()->each(function (Bet $bet) use ($fight) {
                $this->walletService->creditBet(
                    $bet->user->wallet,
                    $bet->amount,
                    'refund',
                    $bet->id,
                    "Pool subset voided (one-sided) Fight #{$fight->fight_number}"
                );
                $bet->update(['status' => 'refunded', 'payout' => $bet->amount]);
            });

            return;
        }

        Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')->where('side', $losingSide)
            ->update(['status' => 'settled', 'payout' => 0.00]);

        $winnerPool = $winner === 'meron' ? $meronPool : $walaPool;
        if ($winnerPool <= 0) {
            Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')->where('side', $winner)
                ->update(['status' => 'settled', 'payout' => 0.00]);

            return;
        }

        $winningBets = Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')->where('side', $winner)
            ->with('user.wallet')->lockForUpdate()->get();

        foreach ($winningBets as $bet) {
            $payout = (int) floor($bet->amount * $payoutRatio);
            $this->walletService->creditBet(
                $bet->user->wallet,
                $payout,
                'payout',
                $bet->id,
                ucfirst($winner)." wins Fight #{$fight->fight_number}"
            );
            $bet->update(['status' => 'settled', 'payout' => $payout]);
        }

        Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')->whereIn('side', ['meron', 'wala'])
            ->whereNotNull('user_id')
            ->get()
            ->each(fn (Bet $poolBet) => $this->commissionService->distributeCommission($poolBet, (float) $poolBet->amount));
    }

    /**
     * Odds subset: fixed-odds matched bets (odds_tier_id set). Uses this
     * fight's own Game plasada/mode.
     */
    private function settleOddsSubset(Fight $fight, string $winner, float $plasada, string $plasadaMode): void
    {
        $oddsBetsQuery = fn () => Bet::where('fight_id', $fight->id)->whereNotNull('odds_tier_id');

        if (! $oddsBetsQuery()->whereIn('status', ['pending', 'partially_matched', 'matched'])->exists()) {
            return;
        }

        $oddsBetsQuery()->update(['status' => 'settled', 'payout' => 0.00]);

        if ($winner === 'draw') {
            $this->refundOddsMatchesOnDraw($fight);
        } else {
            $this->settleOddsRegularWinner($fight, $winner, $plasada, $plasadaMode);
        }
    }

    private function settleOddsRegularWinner(Fight $fight, string $winner, float $plasada, string $plasadaMode): void
    {
        $betMatches = BetMatch::whereHas('meronBet', fn ($q) => $q->where('fight_id', $fight->id))
            ->with(['meronBet.user.wallet', 'walaBet.user.wallet', 'oddsTier'])
            ->get();

        $meronCommissions = [];
        $walaCommissions = [];
        $winnerPayouts = [];

        foreach ($betMatches as $match) {
            $walaContrib = round(
                (float) $match->matched_amount * ((float) $match->oddsTier->wala_ratio / (float) $match->oddsTier->meron_ratio),
                2
            );

            $rakeBase = $plasadaMode === 'total_pool'
                ? (float) $match->matched_amount + $walaContrib
                : ($winner === 'meron' ? $walaContrib : (float) $match->matched_amount);

            $rake = round($rakeBase * $plasada / 100, 2);

            if ($winner === 'meron') {
                $payout = round((float) $match->matched_amount + $walaContrib - $rake, 2);
                $winnerPayouts[$match->meron_bet_id] ??= ['bet' => $match->meronBet, 'payout' => 0.0, 'matched' => 0.0];
                $winnerPayouts[$match->meron_bet_id]['payout'] += $payout;
                $winnerPayouts[$match->meron_bet_id]['matched'] += (float) $match->matched_amount;

                $walaCommissions[$match->wala_bet_id] ??= ['bet' => $match->walaBet, 'amount' => 0.0];
                $walaCommissions[$match->wala_bet_id]['amount'] += $walaContrib;
                $meronCommissions[$match->meron_bet_id] ??= ['bet' => $match->meronBet, 'amount' => 0.0];
                $meronCommissions[$match->meron_bet_id]['amount'] += (float) $match->matched_amount;
            } else {
                $payout = round($walaContrib + (float) $match->matched_amount - $rake, 2);
                $winnerPayouts[$match->wala_bet_id] ??= ['bet' => $match->walaBet, 'payout' => 0.0, 'matched' => 0.0];
                $winnerPayouts[$match->wala_bet_id]['payout'] += $payout;
                $winnerPayouts[$match->wala_bet_id]['matched'] += $walaContrib;

                $meronCommissions[$match->meron_bet_id] ??= ['bet' => $match->meronBet, 'amount' => 0.0];
                $meronCommissions[$match->meron_bet_id]['amount'] += (float) $match->matched_amount;
                $walaCommissions[$match->wala_bet_id] ??= ['bet' => $match->walaBet, 'amount' => 0.0];
                $walaCommissions[$match->wala_bet_id]['amount'] += $walaContrib;
            }
        }

        $winnerLabel = ucfirst($winner)." wins Fight #{$fight->fight_number}";
        foreach ($winnerPayouts as $betId => ['bet' => $bet, 'payout' => $total, 'matched' => $matched]) {
            $description = $winnerLabel.' — matched $'.number_format($matched, 2);
            $this->walletService->creditBet($bet->user->wallet, round($total, 2), 'payout', $betId, $description);
            $bet->increment('payout', round($total, 2));
        }

        foreach ($meronCommissions as ['bet' => $bet, 'amount' => $amount]) {
            if ($bet->user_id) {
                $this->commissionService->distributeCommission($bet, round($amount, 2));
            }
        }
        foreach ($walaCommissions as ['bet' => $bet, 'amount' => $amount]) {
            if ($bet->user_id) {
                $this->commissionService->distributeCommission($bet, round($amount, 2));
            }
        }
    }

    private function refundOddsMatchesOnDraw(Fight $fight): void
    {
        $betMatches = BetMatch::whereHas('meronBet', fn ($q) => $q->where('fight_id', $fight->id))
            ->with(['meronBet.user.wallet', 'walaBet.user.wallet', 'oddsTier'])
            ->get();

        $meronRefunds = [];
        $walaRefunds = [];

        foreach ($betMatches as $match) {
            $walaContrib = round(
                (float) $match->matched_amount * ((float) $match->oddsTier->wala_ratio / (float) $match->oddsTier->meron_ratio),
                2
            );

            $meronRefunds[$match->meron_bet_id] ??= ['bet' => $match->meronBet, 'amount' => 0.0];
            $meronRefunds[$match->meron_bet_id]['amount'] += (float) $match->matched_amount;

            $walaRefunds[$match->wala_bet_id] ??= ['bet' => $match->walaBet, 'amount' => 0.0];
            $walaRefunds[$match->wala_bet_id]['amount'] += $walaContrib;
        }

        $drawLabel = "Draw declared — refund Fight #{$fight->fight_number}";
        foreach ($meronRefunds as $betId => ['bet' => $bet, 'amount' => $total]) {
            $this->walletService->creditBet($bet->user->wallet, round($total, 2), 'refund', $betId, $drawLabel);
            $bet->increment('payout', round($total, 2));
        }
        foreach ($walaRefunds as $betId => ['bet' => $bet, 'amount' => $total]) {
            $this->walletService->creditBet($bet->user->wallet, round($total, 2), 'refund', $betId, $drawLabel);
            $bet->increment('payout', round($total, 2));
        }
    }

    /**
     * Shared draw subset — side='draw' bets, regardless of which panel
     * placed them. Multiplier comes from Game::draw_multiplier, the same
     * single source Pool Sabong uses, funded from the superadmin's wallet
     * the same way.
     */
    private function settleDrawSubset(Fight $fight, string $winner, Game $game): void
    {
        $drawBetsQuery = fn () => Bet::where('fight_id', $fight->id)->where('side', 'draw');

        if (! $drawBetsQuery()->where('status', 'matched')->exists()) {
            return;
        }

        if ($winner !== 'draw') {
            $drawBetsQuery()->update(['status' => 'settled', 'payout' => 0.00]);

            return;
        }

        $multiplier = (float) ($game->draw_multiplier ?? 8.00);

        $drawBets = $drawBetsQuery()->with('user.wallet')->lockForUpdate()->get();

        $totalDrawPaid = (int) $drawBets->sum(fn ($b) => floor($b->amount * $multiplier));

        if ($totalDrawPaid > 0) {
            $admin = User::role('superadmin')->first();
            if ($admin?->wallet) {
                $this->walletService->debitBet(
                    $admin->wallet,
                    $totalDrawPaid,
                    'draw_payout',
                    null,
                    "Draw payouts funded Fight #{$fight->fight_number}"
                );
            }
        }

        foreach ($drawBets as $bet) {
            $payout = (int) floor($bet->amount * $multiplier);
            $this->walletService->creditBet(
                $bet->user->wallet,
                $payout,
                'payout',
                $bet->id,
                "Draw wins Fight #{$fight->fight_number}"
            );
            $bet->update(['status' => 'settled', 'payout' => $payout]);
        }
    }

    /**
     * Cancel a bet's unmatched portion while the fight is still open. Only
     * meaningful for odds-mode bets that aren't fully matched — pool-mode
     * and draw bets always carry unmatched_amount = 0 from placement.
     */
    public function cancelBet(Bet $bet, Fight $fight): void
    {
        DB::transaction(function () use ($bet, $fight) {
            $freshFight = Fight::lockForUpdate()->findOrFail($fight->id);
            if (! $freshFight->isBettable()) {
                throw new \InvalidArgumentException(__('Betting is closed.'));
            }

            $freshBet = Bet::lockForUpdate()->findOrFail($bet->id);
            $unmatched = round((float) $freshBet->unmatched_amount, 2);
            $matched = round((float) $freshBet->matched_amount, 2);

            if ($unmatched <= 0) {
                throw new \InvalidArgumentException(__('Your bet is fully matched and cannot be cancelled.'));
            }

            $wallet = $freshBet->user->wallet()->lockForUpdate()->first();
            $this->walletService->creditBet(
                $wallet,
                $unmatched,
                'refund',
                $freshBet->id,
                $matched > 0
                    ? "Unmatched portion returned Fight #{$fight->fight_number}"
                    : "Bet cancelled Fight #{$fight->fight_number}"
            );

            if ($matched > 0) {
                $freshBet->update([
                    'amount' => $matched,
                    'unmatched_amount' => 0.00,
                    'status' => 'matched',
                ]);
            } else {
                $freshBet->update([
                    'matched_amount' => 0.00,
                    'unmatched_amount' => 0.00,
                    'status' => 'cancelled',
                ]);
            }
        });

        Cache::forget("combined.fight.{$fight->id}.totals");

        $this->broadcastPoolUpdate($fight, $fight->event->game);
    }

    /**
     * Refund unmatched odds-mode meron/wala bets on fight close. Pool-mode
     * and draw bets never carry an unmatched portion (always fully
     * matched at placement), so this naturally only touches the odds
     * subset.
     */
    public function refundUnmatched(Fight $fight): void
    {
        DB::transaction(function () use ($fight) {
            $bets = Bet::where('fight_id', $fight->id)
                ->whereIn('side', ['meron', 'wala'])
                ->where('unmatched_amount', '>', 0)
                ->lockForUpdate()
                ->with('user.wallet')
                ->get();

            foreach ($bets as $bet) {
                $amount = (float) $bet->unmatched_amount;
                $this->walletService->creditBet(
                    $bet->user->wallet,
                    $amount,
                    'refund',
                    $bet->id,
                    "Unmatched refund for Fight #{$fight->fight_number}"
                );
                $newStatus = (float) $bet->matched_amount > 0 ? $bet->status : 'refunded';
                $bet->update(['unmatched_amount' => 0.00, 'status' => $newStatus]);
            }
        });

        Cache::forget("combined.fight.{$fight->id}.totals");

        $this->broadcastPoolUpdate($fight, $fight->event->game);
    }

    /**
     * Refund every bet on the fight in full, across both subsets and draw
     * — used when a fight (pending/open/last_call/closed) is cancelled
     * outright.
     */
    public function refundAll(Fight $fight): void
    {
        DB::transaction(function () use ($fight) {
            $bets = Bet::where('fight_id', $fight->id)
                ->whereNotIn('status', ['refunded', 'cancelled'])
                ->lockForUpdate()
                ->with('user.wallet')
                ->get();

            foreach ($bets as $bet) {
                $this->walletService->creditBet(
                    $bet->user->wallet,
                    $bet->amount,
                    'refund',
                    $bet->id,
                    "Fight #{$fight->fight_number} cancelled — full refund"
                );
                $bet->update(['status' => 'refunded']);
            }
        });
    }

    /**
     * Reverse a fight's settlement (for redeclare): debit back every
     * credited payout, then reset every bet to its own subset's
     * pre-settlement status — pool-mode and draw bets go straight back to
     * 'matched' (they're never order-matched), while odds-mode bets have
     * their status recomputed from matched/unmatched_amount, since
     * BetMatch rows are preserved and reused directly by
     * settleOddsSubset() on re-settle.
     */
    public function reverseSettlement(Fight $fight): void
    {
        DB::transaction(function () use ($fight) {
            $settledBets = Bet::where('fight_id', $fight->id)
                ->where('payout', '>', 0)
                ->lockForUpdate()
                ->with('user.wallet')
                ->get();

            foreach ($settledBets as $bet) {
                $this->walletService->debitUnchecked(
                    $bet->user->wallet,
                    $bet->payout,
                    'reversal',
                    $bet->id,
                    "Payout reversal for Fight #{$fight->fight_number}"
                );
            }

            Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')
                ->whereIn('side', ['meron', 'wala', 'draw'])
                ->update(['status' => 'matched', 'payout' => null]);

            Bet::where('fight_id', $fight->id)->whereNotNull('odds_tier_id')->get()
                ->each(function (Bet $bet) {
                    $bet->payout = null;
                    $bet->save();
                    $this->updateBetStatus($bet);
                });
        });
    }
}
