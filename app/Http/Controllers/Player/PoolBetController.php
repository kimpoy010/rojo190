<?php

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Services\BettingService;
use App\Support\BigRoadLayout;
use App\Support\GameTheme;
use App\Support\PoolPayoutCalculator;
use App\Support\StreamUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PoolBetController extends Controller
{
    public function __construct(private BettingService $bettingService) {}

    public function show(Fight $fight): View|RedirectResponse
    {
        if ($fight->event->status !== 'live') {
            return redirect()->route('play.index')->with('info', __('That event has ended. Please choose another.'));
        }

        if (in_array($fight->status, ['declared', 'cancelled'])) {
            return redirect()->route('play.events.enter', $fight->event);
        }

        $fight->load('cockpit');
        $event = $fight->event->loadMissing('cockpitPreset.cockpits');
        $game = $event->game;
        $wallet = auth()->user()->wallet;
        // Superadmin-controlled per game (Game::video_enabled) — off skips
        // the cockpit/stream lookups entirely rather than just hiding the
        // result, since neither is otherwise used on this page.
        $videoEnabled = $game?->video_enabled ?? true;
        $pipFights = $videoEnabled ? $this->pipFights($fight) : collect();
        $mainStreamUrl = $videoEnabled ? $this->mainStreamUrl($fight) : null;
        $myBets = auth()->user()->bets()->where('fight_id', $fight->id)->latest()->get();

        $meronPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'meron')->sum('amount');
        $walaPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'wala')->sum('amount');
        $drawPool = (float) Bet::inPool()->where('fight_id', $fight->id)->where('side', 'draw')->sum('amount');

        $plasada = $game ? (float) $game->plasada : 5.00;
        $plasadaMode = $game?->plasada_mode ?? 'total_pool';
        $payouts = PoolPayoutCalculator::calculate($meronPool, $walaPool, $plasada, $plasadaMode);

        $drawMultiplier = $game ? (float) $game->draw_multiplier : 8.00;
        $maxDrawBet = $game ? (float) $game->max_draw_bet : 100.00;
        $minPayoutThreshold = $game ? (float) $game->min_payout_threshold : 130.00;

        // The player's own stake on each side of this fight — shown live
        // against the current payout ratio as "my stake = my potential payout".
        $myMeronBet = (float) $myBets->where('side', 'meron')->sum('amount');
        $myWalaBet = (float) $myBets->where('side', 'wala')->sum('amount');
        $myDrawBet = (float) $myBets->where('side', 'draw')->sum('amount');

        // Fights still in play for this event — usually just the current one,
        // but a fight can sit "closed" awaiting declaration while the next
        // one is already open for betting, so players need a way to jump
        // between them instead of only ever seeing whichever one they landed on.
        $activeFights = $event->fights()
            ->whereIn('status', Fight::IN_PLAY_STATUSES)
            ->orderBy('fight_number')
            ->get();

        $fightHistory = $event->fights()
            ->whereIn('status', ['declared', 'cancelled'])
            ->orderBy('fight_number')
            ->limit(48)
            ->get();

        $reglahan = BigRoadLayout::build($fightHistory);

        // Every bet this player has ever placed, across every event (not
        // just this one) — the "Betting history" section below, filterable
        // per event via $myBetHistoryEvents' own dropdown. Paginated (see
        // betHistory() below, which serves the same query for both the
        // filter and page-link AJAX requests) rather than a flat list, so a
        // player with a long history isn't shipped their whole bet log on
        // every fight page load.
        $myBetHistory = $this->myBetHistoryQuery(null, 'open')->paginate(10);

        $myBetHistoryEvents = Event::whereHas('fights.bets', fn ($q) => $q->where('user_id', auth()->id()))
            ->orderByDesc('date')
            ->get(['id', 'name']);

        // Every live event including this one, so a player can jump
        // straight to another without backing out to the lobby — entering
        // one routes them to its own current fight, same as tapping its
        // tile there would. 'game' is eager-loaded for displayBannerUrl()'s
        // fallback to the game's default banner; 'currentFight' feeds each
        // tab's status glow (kept live afterward over that event's own
        // public broadcast channel — see the script below).
        $liveEvents = Event::with(['game', 'currentFight'])
            ->where('status', 'live')
            ->orderBy('date')
            ->get();

        $statsRaw = $event->fights()
            ->selectRaw("
                SUM(winner = 'meron')     as meron_wins,
                SUM(winner = 'wala')      as wala_wins,
                SUM(winner = 'draw')      as draw_wins,
                SUM(status = 'cancelled') as cancelled
            ")
            ->first();

        $stats = [
            'meron' => (int) ($statsRaw->meron_wins ?? 0),
            'wala' => (int) ($statsRaw->wala_wins ?? 0),
            'draw' => (int) ($statsRaw->draw_wins ?? 0),
            'cancelled' => (int) ($statsRaw->cancelled ?? 0),
        ];

        $status = 'open';

        return view('player.pool-betting', compact(
            'fight', 'event', 'game', 'wallet', 'myBets',
            'meronPool', 'walaPool', 'drawPool', 'payouts',
            'drawMultiplier', 'maxDrawBet', 'minPayoutThreshold',
            'myMeronBet', 'myWalaBet', 'myDrawBet',
            'activeFights', 'fightHistory', 'stats', 'reglahan', 'pipFights', 'liveEvents',
            'myBetHistory', 'myBetHistoryEvents', 'status', 'mainStreamUrl', 'videoEnabled',
        ));
    }

    /**
     * The Betting history section's own row list + pagination (see
     * player/partials/bet-history-rows.blade.php), fetched on its own by
     * that section's event filter and by its pagination links — both go
     * through this instead of a full page reload, same pattern as the
     * superadmin wallets page's live search.
     */
    public function betHistory(Request $request): \Illuminate\Http\Response
    {
        $eventId = $request->integer('event_id') ?: null;
        $status = $request->string('status')->value() ?: 'open';
        $myBetHistory = $this->myBetHistoryQuery($eventId, $status)->paginate(10)->withQueryString();

        return response()->view('player.partials.bet-history-rows', compact('myBetHistory', 'status'));
    }

    /**
     * $status splits the list the same way the "Open bets" / "Settled
     * bets" tabs do: 'open' is a bet still waiting on its fight (status
     * 'matched'), 'settled' is everything with an outcome already —
     * settled/voided/refunded. Bet::historyStatusLabel() is what renders
     * each row's own label/color; this only decides which bucket a row
     * falls into.
     */
    private function myBetHistoryQuery(?int $eventId, ?string $status = null): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        // sideLabel() needs label_meron/label_wala/label_draw too — not
        // just 'name' — or it throws on a null column that was simply
        // never selected.
        return auth()->user()->bets()
            ->with(['fight:id,fight_number,event_id', 'fight.event:id,name,label_meron,label_wala,label_draw,game_id', 'fight.event.game:id,region'])
            ->when($eventId, fn ($q) => $q->whereHas('fight', fn ($fq) => $fq->where('event_id', $eventId)))
            ->when($status === 'open', fn ($q) => $q->where('status', 'matched'))
            ->when($status === 'settled', fn ($q) => $q->whereIn('status', ['settled', 'voided', 'refunded']))
            ->latest();
    }

    /**
     * This fight's own cockpit feed takes priority; the event's cockpit
     * preset's primary cockpit is the next fallback; and if neither is set
     * (a fight freshly created right after the previous one was declared,
     * before the declarator has assigned the next one a cockpit), keep
     * showing whichever cockpit this event most recently finished using
     * instead of going blank — a player watching live shouldn't lose the
     * feed just because there's a gap while the next fight gets set up.
     *
     * That last fallback is scoped to fights that have actually finished
     * (declared/cancelled, not still in play) so it never doubles up with
     * a still-in-play fight already shown in the PiP stack (pipFights()
     * only shows Fight::IN_PLAY_STATUSES).
     */
    private function mainStreamUrl(Fight $fight): ?string
    {
        if ($fight->cockpit?->stream_url) {
            return $fight->cockpit->stream_url;
        }

        if ($primary = $fight->event->primaryStreamUrl()) {
            return $primary;
        }

        return Fight::where('event_id', $fight->event_id)
            ->whereIn('status', ['declared', 'cancelled'])
            ->whereNotNull('cockpit_id')
            ->with('cockpit')
            ->orderByDesc('fight_number')
            ->get()
            ->first(fn (Fight $candidate) => filled($candidate->cockpit?->stream_url))
            ?->cockpit
            ?->stream_url;
    }

    /**
     * Every OTHER fight — same event or a different one — the player still
     * has an unsettled bet riding on. 'matched' is the pool-bet status
     * before settlement (see BettingService); once it settles, the fight
     * drops off the PiP on its own. Excludes this fight's own cockpit so
     * the main video never also shows as a PiP tile of itself, and
     * anything without its own stream.
     *
     * @return Collection<int, array{fight_id: int, fight_number: int, stream_url: string}>
     */
    private function pipFights(Fight $fight): Collection
    {
        $myOpenBetFightIds = Bet::where('user_id', auth()->id())
            ->where('status', 'matched')
            ->where('fight_id', '!=', $fight->id)
            ->pluck('fight_id');

        return Fight::whereIn('id', $myOpenBetFightIds)
            ->whereIn('status', Fight::IN_PLAY_STATUSES)
            ->whereNotNull('cockpit_id')
            ->when($fight->cockpit_id, fn ($q) => $q->where('cockpit_id', '!=', $fight->cockpit_id))
            ->with(['cockpit', 'event'])
            ->get()
            ->sortBy('fight_number')
            ->filter(fn (Fight $candidate) => filled($candidate->cockpit?->stream_url))
            ->map(fn (Fight $candidate) => [
                'fight_id' => $candidate->id,
                'fight_number' => $candidate->fight_number,
                'event_name' => $candidate->event_id === $fight->event_id ? null : $candidate->event->name,
                'stream_url' => StreamUrl::autoplayMuted($candidate->cockpit->stream_url),
            ])
            ->values();
    }

    public function status(Fight $fight): JsonResponse
    {
        $fresh = $fight->fresh();
        $fresh->load('cockpit');
        $fresh->event->loadMissing('cockpitPreset.cockpits');
        $game = $fresh->event->game;
        $plasada = $game ? (float) $game->plasada : 5.00;
        $plasadaMode = $game?->plasada_mode ?? 'total_pool';

        $data = Cache::remember("pool.fight.{$fresh->id}.totals", 5, function () use ($fresh, $plasada, $plasadaMode) {
            $pools = Bet::inPool()->where('fight_id', $fresh->id)
                ->selectRaw("
                    SUM(CASE WHEN side='meron' THEN amount ELSE 0 END) as meron,
                    SUM(CASE WHEN side='wala'  THEN amount ELSE 0 END) as wala,
                    SUM(CASE WHEN side='draw'  THEN amount ELSE 0 END) as draw
                ")
                ->first();

            $meron = (float) $pools->meron;
            $wala = (float) $pools->wala;
            $draw = (float) $pools->draw;
            $payouts = PoolPayoutCalculator::calculate($meron, $wala, $plasada, $plasadaMode);

            return [
                'meron' => $meron,
                'wala' => $wala,
                'draw' => $draw,
                'meron_payout' => $payouts['meron'],
                'wala_payout' => $payouts['wala'],
                'meron_payout_pct' => $payouts['meron_pct'],
                'wala_payout_pct' => $payouts['wala_pct'],
            ];
        });

        // Re-sent on every poll (not just once at page load) so the fight
        // switcher stays current when another fight for this event starts,
        // closes, or gets declared while this page is open.
        $activeFights = Fight::where('event_id', $fresh->event_id)
            ->whereIn('status', Fight::IN_PLAY_STATUSES)
            ->orderBy('fight_number')
            ->get(['id', 'fight_number']);

        return response()->json([
            'status' => $fresh->status,
            'winner' => $fresh->winner,
            'fight_number' => $fresh->fight_number,
            'event_id' => $fresh->event_id,
            'meron_pool' => $data['meron'],
            'wala_pool' => $data['wala'],
            'draw_pool' => $data['draw'],
            'meron_payout' => $data['meron_payout'],
            'wala_payout' => $data['wala_payout'],
            'meron_payout_pct' => $data['meron_payout_pct'],
            'wala_payout_pct' => $data['wala_payout_pct'],
            'active_fights' => $activeFights->map(fn (Fight $f) => ['id' => $f->id, 'fight_number' => $f->fight_number]),
            'pip' => ($game?->video_enabled ?? true) ? $this->pipFights($fresh) : [],
            'main_stream_url' => ($game?->video_enabled ?? true) ? $this->mainStreamUrl($fresh) : null,
        ]);
    }

    public function store(Request $request, Fight $fight): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'side' => 'required|in:meron,wala,draw',
            'amount' => 'required|numeric|min:1',
        ]);

        $betLimit = $fight->event->bet_limit;
        if ($betLimit && (float) $data['amount'] > (float) $betLimit) {
            $currency = $fight->event->game?->theme()['currency'] ?? GameTheme::currencySymbol(null);
            $msg = __('Bet exceeds the maximum limit of :limit.', ['limit' => $currency.number_format((float) $betLimit, 0)]);
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }

            return redirect()->route('play.pool-fight', $fight)->with('error', $msg);
        }

        try {
            $this->bettingService->placeBet(auth()->user(), $fight, $data['side'], (float) $data['amount']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()->route('play.pool-fight', $fight)->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            $freshWallet = auth()->user()->wallet()->first();

            return response()->json([
                'success' => true,
                'message' => __('Bet placed successfully.'),
                'wallet_balance' => $freshWallet ? (float) $freshWallet->main_balance : 0,
            ]);
        }

        return redirect()->route('play.pool-fight', $fight)->with('success', __('Bet placed successfully.'));
    }
}
