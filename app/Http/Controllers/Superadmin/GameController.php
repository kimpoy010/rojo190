<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Support\AuditLogger;
use App\Support\GameTheme;
use App\Support\ImageUpload;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GameController extends Controller
{
    public function edit(Game $game): View
    {
        $regions = GameTheme::regions();

        return view('superadmin.games.edit', compact('game', 'regions'));
    }

    public function update(Request $request, Game $game): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => 'required|string|max:255',
            'game_status' => 'required|in:active,inactive',
            'region' => ['required', Rule::in(GameTheme::regions())],
            'video_enabled' => 'sometimes|boolean',
            'default_banner' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'remove_default_banner' => 'sometimes|boolean',
            'plasada' => 'required|numeric|min:0|max:100',
            'plasada_mode' => 'required|in:losing_side,total_pool',
            'draw_multiplier' => 'required|numeric|min:0',
            'max_draw_bet' => 'required|numeric|min:0',
            'min_payout_threshold' => 'required|numeric|min:0',
            ...($game->isCombined() ? ['odds_plasada' => 'required|numeric|min:0|max:100'] : []),
        ]);

        $data['video_enabled'] = $request->boolean('video_enabled');

        $regionChanged = $data['region'] !== $game->region;
        $before = $game->only(['display_name', 'game_status', 'region', 'video_enabled', 'plasada', 'plasada_mode', 'draw_multiplier', 'max_draw_bet', 'min_payout_threshold']);

        $bannerUrl = $game->default_banner_url;

        if ($request->hasFile('default_banner')) {
            ImageUpload::deleteIfLocal($bannerUrl);
            $bannerUrl = ImageUpload::store($request->file('default_banner'), 'game-banners');
        } elseif ($request->boolean('remove_default_banner')) {
            ImageUpload::deleteIfLocal($bannerUrl);
            $bannerUrl = null;
        }

        unset($data['default_banner'], $data['remove_default_banner']);

        $game->update([
            ...$data,
            'default_banner_url' => $bannerUrl,
        ]);

        // Betting labels live on each Event row (so a future per-event
        // override stays possible), but switching the game's region is
        // meant to re-theme every event under it immediately — sync them
        // to the new region's defaults rather than leaving old-market
        // labels behind. Colors don't need this: those are looked up live
        // from the game's region wherever they're rendered.
        if ($regionChanged) {
            $theme = GameTheme::for($data['region']);
            $game->events()->update([
                'label_meron' => $theme['meron']['label'],
                'label_wala' => $theme['wala']['label'],
                'label_draw' => $theme['draw']['label'],
            ]);
        }

        $after = $game->fresh()->only(array_keys($before));
        $changes = [];
        foreach ($before as $field => $oldValue) {
            if ($oldValue != $after[$field]) {
                $changes[$field] = ['old' => $oldValue, 'new' => $after[$field]];
            }
        }

        AuditLogger::log(
            action: 'game.settings_updated',
            description: __('Game settings updated for :name.', ['name' => $game->display_name]),
            target: $game,
            changes: $changes,
        );

        return redirect()->route('superadmin.dashboard')->with('success', __('Game settings updated.'));
    }
}
