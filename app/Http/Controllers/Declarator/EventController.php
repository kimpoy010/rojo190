<?php

namespace App\Http\Controllers\Declarator;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Cockpit;
use App\Models\Event;
use App\Models\Fight;
use App\Services\FightService;
use App\Support\PoolPayoutCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class EventController extends Controller
{
    public function __construct(private FightService $fightService) {}

    public function index(): View
    {
        $events = Event::with('game')->orderByDesc('id')->get();

        return view('declarator.events.index', compact('events'));
    }

    public function show(Event $event): View
    {
        $event->load('game', 'cockpitPreset.cockpits');

        return view('declarator.events.show', [
            'event' => $event,
            ...$this->fightsPanelData($event),
        ]);
    }

    /**
     * The panel re-rendered wholesale (see resources/views/declarator/events/
     * _fights_panel.blade.php) whenever any fight status in this event
     * changes, instead of reloading the whole page — used both for the
     * initial page load above and the AJAX refresh below.
     */
    public function fightsPanel(Event $event): JsonResponse
    {
        $event->load('game', 'cockpitPreset.cockpits');

        $html = view('declarator.events._fights_panel', [
            'event' => $event,
            'theme' => $event->game?->theme() ?? \App\Support\GameTheme::for(null),
            ...$this->fightsPanelData($event),
        ])->render();

        return response()->json(['html' => $html]);
    }

    /**
     * @return array{fights: \Illuminate\Support\Collection, fightHistory: \Illuminate\Database\Eloquent\Collection, cockpits: \Illuminate\Database\Eloquent\Collection, busyCockpits: \Illuminate\Support\Collection}
     */
    private function fightsPanelData(Event $event): array
    {
        // Every fight still needing action, oldest first — normally just
        // the one currently open, but a fight stuck awaiting declaration
        // (a long match, a disputed call) stays here even after a newer
        // fight has been started, so it's never lost track of.
        $activeFights = $event->fights()
            ->whereIn('status', Fight::IN_PLAY_STATUSES)
            ->orderBy('fight_number')
            ->with('cockpit')
            ->get();

        $game = $event->game;
        $fights = $activeFights->map(function (Fight $fight) use ($game) {
            $raw = Bet::inPool()->where('fight_id', $fight->id)
                ->selectRaw("
                    SUM(CASE WHEN side='meron' THEN amount ELSE 0 END) as meron,
                    SUM(CASE WHEN side='wala'  THEN amount ELSE 0 END) as wala,
                    SUM(CASE WHEN side='draw'  THEN amount ELSE 0 END) as draw
                ")
                ->first();

            $poolTotals = [
                'meron' => (float) $raw->meron,
                'wala' => (float) $raw->wala,
                'draw' => (float) $raw->draw,
            ];

            $payouts = PoolPayoutCalculator::calculate(
                $poolTotals['meron'],
                $poolTotals['wala'],
                $game ? (float) $game->plasada : 5.00,
                $game?->plasada_mode ?? 'total_pool'
            );

            return ['fight' => $fight, 'poolTotals' => $poolTotals, 'payouts' => $payouts];
        });

        $fightHistory = $event->fights()
            ->whereIn('status', ['declared', 'cancelled'])
            ->orderByDesc('fight_number')
            ->limit(20)
            ->get();

        // A cockpit can't stream two fights of THIS event at once, but a
        // different event running concurrently is free to use the same
        // cockpit independently (see FightService::assertCockpitAvailable,
        // which enforces the same per-event scope). Keyed by cockpit_id =>
        // the fight currently holding it, so the view can grey out that
        // option everywhere in this event except on the fight that already
        // holds it (e.g. reassigning that same fight to its own cockpit).
        $busyCockpits = $event->fights()
            ->whereIn('status', Fight::IN_PLAY_STATUSES)
            ->whereNotNull('cockpit_id')
            ->pluck('id', 'cockpit_id');

        // Scoped to the event's own cockpit preset, if it has one — an
        // event with no preset assigned (or a legacy one from before
        // presets existed) still falls back to every cockpit rather than
        // leaving the declarator with nothing to pick from.
        $cockpits = $event->cockpitPreset
            ? $event->cockpitPreset->cockpits
            : Cockpit::orderBy('id')->get();

        return [
            'fights' => $fights,
            'fightHistory' => $fightHistory,
            'cockpits' => $cockpits,
            'busyCockpits' => $busyCockpits,
        ];
    }

    public function startNextFight(Event $event): JsonResponse|RedirectResponse
    {
        try {
            $this->fightService->startNextFight($event);
        } catch (\InvalidArgumentException $e) {
            if (request()->ajax() || request()->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return redirect()->route('declarator.events.show', $event)->with('error', $e->getMessage());
        }

        if (request()->ajax() || request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => __('Next fight started.')]);
        }

        return redirect()->route('declarator.events.show', $event)->with('success', __('Next fight started.'));
    }

    public function start(Event $event): RedirectResponse
    {
        if ($event->status !== 'upcoming') {
            return back()->with('error', __('Only upcoming events can be started.'));
        }

        $event->update(['status' => 'live']);

        if (! $event->fights()->exists()) {
            Fight::create([
                'event_id' => $event->id,
                'fight_number' => 1,
                'status' => 'pending',
                'draw_enabled' => $event->draw_enabled,
            ]);
        }

        return redirect()->route('declarator.events.show', $event)->with('success', __('Event started.'));
    }

    public function end(Event $event): RedirectResponse
    {
        $event->update(['status' => 'completed']);

        return redirect()->route('declarator.events.index')->with('success', __('Event ended.'));
    }
}
