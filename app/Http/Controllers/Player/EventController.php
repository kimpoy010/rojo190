<?php

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Support\GameTheme;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class EventController extends Controller
{
    public function index(): View
    {
        $liveEvents = Event::with(['game', 'currentFight.cockpit', 'cockpitPreset.cockpits'])->where('status', 'live')->orderBy('date')->get();
        $upcomingEvents = Event::with('game')->where('status', 'upcoming')->orderBy('date')->get();

        // The tile border comet's two colors (see the live-tile-neon CSS
        // in player.index) are region-based, not per-event — the app is
        // single-tenant (one pool-sabong Game row), so pull the theme from
        // that game rather than any one event, which would be wrong for
        // an all-upcoming lobby with no live event to read it from.
        $theme = Game::where('game_name', 'pool-sabong')->first()?->theme() ?? GameTheme::for(null);

        $ongoingFights = $this->ongoingFights($liveEvents);

        return view('player.index', compact('liveEvents', 'upcomingEvents', 'ongoingFights', 'theme'));
    }

    /**
     * Every OTHER still-in-play fight of a live event — an event's own
     * tile above already shows its `currentFight` (the highest-numbered
     * in-play fight), so an event running two rings at once (e.g. an
     * older fight on ring 1 awaiting declaration while ring 2 already
     * opened its next one) would otherwise leave that second fight with
     * no way to find it from the lobby at all. Same "in play, has its own
     * cockpit stream" rule as the fight page's PiP list
     * (PoolBetController::pipFights) — still scoped to fights with a
     * configured stream even though the tile itself now just shows the
     * event's banner, since a fight with no stream at all isn't really
     * "watchable" from here yet.
     *
     * @return Collection<int, array{fight: Fight, event: Event}>
     */
    private function ongoingFights(Collection $liveEvents): Collection
    {
        $currentFightIds = $liveEvents->pluck('currentFight.id')->filter();

        return Fight::with(['event', 'cockpit'])
            ->whereIn('event_id', $liveEvents->pluck('id'))
            ->whereIn('status', Fight::IN_PLAY_STATUSES)
            ->whereNotNull('cockpit_id')
            ->when($currentFightIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $currentFightIds))
            ->orderBy('fight_number')
            ->get()
            ->filter(fn (Fight $fight) => filled($fight->cockpit?->stream_url))
            ->map(fn (Fight $fight) => [
                'fight' => $fight,
                'event' => $fight->event,
            ])
            ->values();
    }

    public function enter(Event $event): \Illuminate\Http\RedirectResponse
    {
        if ($event->status !== 'live') {
            return redirect()->route('play.index')->with('info', __('That event is not live right now.'));
        }

        $fight = $event->currentFight()->first();

        if (! $fight) {
            return redirect()->route('play.index')->with('info', __('No fight is currently in progress for that event.'));
        }

        if ($event->game?->isCombined()) {
            return redirect()->route('play.combined-fight', $fight);
        }

        return redirect()->route('play.pool-fight', $fight);
    }
}
