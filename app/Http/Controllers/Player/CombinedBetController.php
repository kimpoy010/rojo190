<?php

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Fight;
use App\Models\OddsTier;
use App\Services\CombinedBettingService;
use App\Support\GameTheme;
use App\Support\PoolPayoutCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CombinedBetController extends Controller
{
    public function __construct(private CombinedBettingService $bettingService) {}

    public function show(Fight $fight): View|RedirectResponse
    {
        if ($fight->event->status !== 'live') {
            return redirect()->route('play.index')->with('info', __('That event has ended. Please choose another.'));
        }

        if (in_array($fight->status, ['declared', 'cancelled'], true)) {
            return redirect()->route('play.events.enter', $fight->event);
        }

        $event = $fight->event;
        $game = $event->game;
        $wallet = auth()->user()->wallet;
        $theme = $game?->theme() ?? GameTheme::for(null);

        $oddsTiers = $this->tiersForEvent($event);

        $myBets = auth()->user()->bets()
            ->where('fight_id', $fight->id)
            ->whereNotIn('status', ['refunded', 'cancelled'])
            ->get();

        [$poolTotals, $payouts] = $this->poolTotals($fight, $game);
        $tierTotals = $this->tierTotals($fight);

        $myMeronPool = (float) $myBets->where('side', 'meron')->whereNull('odds_tier_id')->sum('amount');
        $myWalaPool = (float) $myBets->where('side', 'wala')->whereNull('odds_tier_id')->sum('amount');
        $myDrawBet = (float) $myBets->where('side', 'draw')->sum('amount');

        $myTierBets = $myBets->whereNotNull('odds_tier_id')->groupBy(fn (Bet $b) => $b->odds_tier_id.'-'.$b->side)
            ->map(fn ($rows) => (float) $rows->sum(fn (Bet $b) => (float) $b->matched_amount + (float) $b->unmatched_amount));

        $drawMultiplier = $game ? (float) $game->draw_multiplier : 8.00;
        $maxDrawBet = $game ? (float) $game->max_draw_bet : 100.00;

        $drawPool = (float) Bet::where('fight_id', $fight->id)
            ->where('side', 'draw')
            ->whereNotIn('status', ['refunded', 'cancelled'])
            ->sum('amount');

        return view('player.combined-betting', [
            'fight' => $fight,
            'event' => $event,
            'game' => $game,
            'wallet' => $wallet,
            'theme' => $theme,
            'oddsTiers' => $oddsTiers,
            'poolTotals' => $poolTotals,
            'payouts' => $payouts,
            'tierTotals' => $tierTotals,
            'myMeronPool' => $myMeronPool,
            'myWalaPool' => $myWalaPool,
            'myDrawBet' => $myDrawBet,
            'myTierBets' => $myTierBets,
            'drawMultiplier' => $drawMultiplier,
            'maxDrawBet' => $maxDrawBet,
            'drawPool' => $drawPool,
        ]);
    }

    public function status(Fight $fight): JsonResponse
    {
        $fresh = $fight->fresh();
        $game = $fresh->event->game;

        [$poolTotals, $payouts] = $this->poolTotals($fresh, $game);
        $tierTotals = $this->tierTotals($fresh);

        $drawPool = (float) Bet::where('fight_id', $fresh->id)
            ->where('side', 'draw')
            ->whereNotIn('status', ['refunded', 'cancelled'])
            ->sum('amount');

        return response()->json([
            'status' => $fresh->status,
            'winner' => $fresh->winner,
            'fight_number' => $fresh->fight_number,
            'meron_pool' => $poolTotals['meron'],
            'wala_pool' => $poolTotals['wala'],
            'draw_pool' => $drawPool,
            'meron_payout' => $payouts['meron'],
            'wala_payout' => $payouts['wala'],
            'meron_payout_pct' => $payouts['meron_pct'],
            'wala_payout_pct' => $payouts['wala_pct'],
            'tier_totals' => $tierTotals,
        ]);
    }

    public function store(Request $request, Fight $fight): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:pool,odds',
            'side' => 'required|in:meron,wala,draw',
            'amount' => 'required|numeric|min:1',
            'odds_tier_id' => 'nullable|integer|exists:odds_tiers,id',
        ]);

        $betLimit = $fight->event->bet_limit;
        if ($betLimit && (float) $data['amount'] > (float) $betLimit) {
            $currency = $fight->event->game?->theme()['currency'] ?? GameTheme::currencySymbol(null);
            $msg = __('Bet exceeds the maximum limit of :limit.', ['limit' => $currency.number_format((float) $betLimit, 0)]);

            return $this->fail($request, $fight, $msg);
        }

        $oddsTier = $data['mode'] === 'odds' && $data['side'] !== 'draw' && ! empty($data['odds_tier_id'])
            ? OddsTier::find($data['odds_tier_id'])
            : null;

        try {
            $this->bettingService->placeBet(auth()->user(), $fight, $oddsTier, $data['side'], $data['mode'], (float) $data['amount']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->fail($request, $fight, $e->getMessage());
        }

        if ($request->expectsJson()) {
            $freshWallet = auth()->user()->wallet()->first();

            return response()->json([
                'success' => true,
                'message' => __('Bet placed successfully.'),
                'wallet_balance' => $freshWallet ? (float) $freshWallet->main_balance : 0,
            ]);
        }

        return redirect()->route('play.combined-fight', $fight)->with('success', __('Bet placed successfully.'));
    }

    public function cancelBet(Fight $fight, Bet $bet): JsonResponse|RedirectResponse
    {
        if ($bet->user_id !== auth()->id()) {
            abort(403);
        }

        try {
            $this->bettingService->cancelBet($bet, $fight);
        } catch (\InvalidArgumentException $e) {
            return $this->fail(request(), $fight, $e->getMessage());
        }

        if (request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => __('Bet cancelled.')]);
        }

        return redirect()->route('play.combined-fight', $fight)->with('success', __('Bet cancelled.'));
    }

    private function fail(Request $request, Fight $fight, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 422);
        }

        return redirect()->route('play.combined-fight', $fight)->with('error', $message);
    }

    /**
     * @return \Illuminate\Support\Collection<int, OddsTier>
     */
    private function tiersForEvent($event): \Illuminate\Support\Collection
    {
        $assigned = $event->oddsTiers;

        return $assigned->isNotEmpty() ? $assigned : OddsTier::where('is_active', true)->get();
    }

    private function poolTotals(Fight $fight, $game): array
    {
        $meron = (float) Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')->where('side', 'meron')
            ->whereNotIn('status', ['refunded', 'cancelled'])->sum('amount');
        $wala = (float) Bet::where('fight_id', $fight->id)->whereNull('odds_tier_id')->where('side', 'wala')
            ->whereNotIn('status', ['refunded', 'cancelled'])->sum('amount');

        $plasada = $game ? (float) $game->plasada : 5.00;
        $plasadaMode = $game?->plasada_mode ?? 'total_pool';
        $payouts = PoolPayoutCalculator::calculate($meron, $wala, $plasada, $plasadaMode);

        return [['meron' => $meron, 'wala' => $wala], $payouts];
    }

    /**
     * @return array<int, array{meron: float, wala: float}>
     */
    private function tierTotals(Fight $fight): array
    {
        return Bet::where('fight_id', $fight->id)
            ->whereNotNull('odds_tier_id')
            ->whereIn('side', ['meron', 'wala'])
            ->whereNotIn('status', ['refunded', 'cancelled'])
            ->selectRaw('odds_tier_id, side, SUM(matched_amount + unmatched_amount) as total')
            ->groupBy('odds_tier_id', 'side')
            ->get()
            ->groupBy('odds_tier_id')
            ->map(fn ($rows) => [
                'meron' => (float) ($rows->firstWhere('side', 'meron')?->total ?? 0),
                'wala' => (float) ($rows->firstWhere('side', 'wala')?->total ?? 0),
            ])
            ->toArray();
    }
}
