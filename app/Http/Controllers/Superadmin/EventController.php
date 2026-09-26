<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\CockpitPreset;
use App\Models\Event;
use App\Models\Game;
use App\Models\OddsTier;
use App\Support\AuditLogger;
use App\Support\GameTheme;
use App\Support\ImageUpload;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function create(): View
    {
        $games = Game::orderBy('display_name')->get();
        $cockpitPresets = CockpitPreset::orderBy('name')->get();
        $oddsTiers = OddsTier::where('is_active', true)->orderBy('label')->get();

        return view('superadmin.events.create', compact('games', 'cockpitPresets', 'oddsTiers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'game_id' => 'required|integer|exists:games,id',
            'name' => 'required|string|max:255',
            'arena' => 'nullable|string|max:255',
            'date' => 'nullable|date',
            'cockpit_preset_id' => 'nullable|integer|exists:cockpit_presets,id',
            'thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'draw_enabled' => 'sometimes|boolean',
            'multiplier' => 'nullable|numeric|min:0.01',
            'bet_limit' => 'nullable|numeric|min:0',
            'odds_tier_ids' => 'sometimes|array',
            'odds_tier_ids.*' => 'integer|exists:odds_tiers,id',
        ]);

        $game = Game::findOrFail($data['game_id']);
        $theme = GameTheme::for($game->region);

        $event = Event::create([
            'game_id' => $game->id,
            'cockpit_preset_id' => $data['cockpit_preset_id'] ?? null,
            'name' => $data['name'],
            'arena' => $data['arena'] ?? null,
            'date' => $data['date'] ?? null,
            'thumbnail_url' => $request->hasFile('thumbnail')
                ? ImageUpload::store($request->file('thumbnail'), 'event-thumbnails')
                : null,
            'draw_enabled' => $request->boolean('draw_enabled'),
            'multiplier' => $data['multiplier'] ?? 1,
            'bet_limit' => $data['bet_limit'] ?? null,
            'status' => 'upcoming',
            'label_meron' => $theme['meron']['label'],
            'label_wala' => $theme['wala']['label'],
            'label_draw' => $theme['draw']['label'],
        ]);

        if ($game->isCombined()) {
            $this->syncOddsTiers($event, $data['odds_tier_ids'] ?? []);
        }

        AuditLogger::log(
            action: 'event.created',
            description: __('Event :name created.', ['name' => $event->name]),
            target: $event,
        );

        return redirect()->route('declarator.events.show', $event)->with('success', __('Event created.'));
    }

    public function edit(Event $event): View
    {
        $cockpitPresets = CockpitPreset::orderBy('name')->get();
        $oddsTiers = OddsTier::where('is_active', true)->orderBy('label')->get();
        $event->loadMissing('game', 'oddsTiers');

        return view('superadmin.events.edit', compact('event', 'cockpitPresets', 'oddsTiers'));
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'arena' => 'nullable|string|max:255',
            'date' => 'nullable|date',
            'cockpit_preset_id' => 'nullable|integer|exists:cockpit_presets,id',
            'thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'remove_thumbnail' => 'sometimes|boolean',
            'draw_enabled' => 'sometimes|boolean',
            'multiplier' => 'nullable|numeric|min:0.01',
            'bet_limit' => 'nullable|numeric|min:0',
            'odds_tier_ids' => 'sometimes|array',
            'odds_tier_ids.*' => 'integer|exists:odds_tiers,id',
        ]);

        $thumbnailUrl = $event->thumbnail_url;

        if ($request->hasFile('thumbnail')) {
            ImageUpload::deleteIfLocal($thumbnailUrl);
            $thumbnailUrl = ImageUpload::store($request->file('thumbnail'), 'event-thumbnails');
        } elseif ($request->boolean('remove_thumbnail')) {
            ImageUpload::deleteIfLocal($thumbnailUrl);
            $thumbnailUrl = null;
        }

        $drawEnabled = $request->boolean('draw_enabled');
        $drawEnabledChanged = $drawEnabled !== $event->draw_enabled;
        $before = $event->only(['name', 'arena', 'date', 'draw_enabled', 'multiplier', 'bet_limit']);

        $event->update([
            'name' => $data['name'],
            'arena' => $data['arena'] ?? null,
            'date' => $data['date'] ?? null,
            'cockpit_preset_id' => $data['cockpit_preset_id'] ?? null,
            'thumbnail_url' => $thumbnailUrl,
            'draw_enabled' => $drawEnabled,
            'multiplier' => $data['multiplier'] ?? 1,
            'bet_limit' => $data['bet_limit'] ?? null,
        ]);

        // A fight's own draw_enabled is copied from the event only once, at
        // fight-creation time (FightService::startNextFight/startEvent) — an
        // event edit alone never touched fights already created, so the
        // Draw section on an already-created (but not yet bet-on) fight
        // kept showing/hiding based on stale state. Sync it forward now,
        // but only to fights nobody has placed a draw bet on yet — a fight
        // that already took draw money keeps its own draw_enabled so its
        // declaration and payouts stay valid regardless of what the event
        // gets edited to afterward.
        if ($drawEnabledChanged) {
            $event->fights()
                ->whereDoesntHave('bets', fn ($q) => $q->where('side', 'draw'))
                ->update(['draw_enabled' => $drawEnabled]);
        }

        if ($event->game?->isCombined()) {
            $this->syncOddsTiers($event, $data['odds_tier_ids'] ?? []);
        }

        $after = $event->fresh()->only(array_keys($before));
        $changes = [];
        foreach ($before as $field => $oldValue) {
            if ($oldValue != $after[$field]) {
                $changes[$field] = ['old' => $oldValue, 'new' => $after[$field]];
            }
        }

        AuditLogger::log(
            action: 'event.updated',
            description: __('Event :name updated.', ['name' => $event->name]),
            target: $event,
            changes: $changes,
        );

        return redirect()->route('declarator.events.show', $event)->with('success', __('Event updated.'));
    }

    /**
     * CombinedSabong only — which odds tiers this event offers, in the
     * order submitted. An empty selection is valid (falls back to every
     * globally active tier — see CombinedBettingService::placeBet()).
     */
    private function syncOddsTiers(Event $event, array $oddsTierIds): void
    {
        $sync = [];
        foreach (array_values($oddsTierIds) as $order => $tierId) {
            $sync[$tierId] = ['display_order' => $order];
        }

        $event->oddsTiers()->sync($sync);
    }
}
