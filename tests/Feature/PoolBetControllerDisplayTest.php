<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PoolBetControllerDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_pool_amounts_are_scaled_by_the_event_display_multiplier(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $game = Game::create([
            'game_name' => 'pool-sabong',
            'display_name' => 'Pool Sabong',
            'plasada' => 5.00,
            'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        $event = Event::create([
            'game_id' => $game->id,
            'name' => 'Test Card',
            'status' => 'live',
            'draw_enabled' => true,
            'multiplier' => 3,
        ]);

        $fight = Fight::create([
            'event_id' => $event->id,
            'fight_number' => 1,
            'status' => 'open',
            'draw_enabled' => true,
        ]);

        $bettor = User::factory()->create();
        $bettor->assignRole('player');
        Wallet::create(['user_id' => $bettor->id, 'main_balance' => 1000]);

        Bet::create(['user_id' => $bettor->id, 'fight_id' => $fight->id, 'side' => 'meron', 'amount' => 100, 'status' => 'matched']);

        $response = $this->actingAs($bettor)->get(route('play.pool-fight', $fight));

        // Raw pool is 100; with a display multiplier of 3 the page should show 300 —
        // this is purely cosmetic and must never touch the real bet amount.
        $response->assertSee('id="meron-pool">300.00<', false);
        $this->assertEquals(100, (float) Bet::first()->amount);
    }

    /**
     * Regression: the BetPoolUpdated broadcast handler used to reuse the
     * page's own initial Blade-rendered status ('{{ $fight->status }}')
     * every time anyone placed a bet on this fight. That snapshot goes
     * stale the instant a FightStatusUpdated broadcast changes the real
     * status (e.g. betting opens after this page was loaded) — the very
     * next bet anyone placed would snap the badge back to whatever status
     * was true at page-load, "PENDING" included, even on an open fight.
     */
    public function test_bet_pool_broadcast_handler_tracks_live_status_not_the_page_load_snapshot(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $game = Game::create([
            'game_name' => 'pool-sabong',
            'display_name' => 'Pool Sabong',
            'plasada' => 5.00,
            'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        $event = Event::create([
            'game_id' => $game->id,
            'name' => 'Test Card',
            'status' => 'live',
            'draw_enabled' => true,
        ]);

        // The page can be loaded while a fight is still pending — the
        // BetPoolUpdated handler must not bake that moment's status into
        // the broadcast listener.
        $fight = Fight::create([
            'event_id' => $event->id,
            'fight_number' => 1,
            'status' => 'pending',
            'draw_enabled' => true,
        ]);

        $bettor = User::factory()->create();
        $bettor->assignRole('player');
        Wallet::create(['user_id' => $bettor->id, 'main_balance' => 1000]);

        $response = $this->actingAs($bettor)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertDontSee("status: '{{ \$fight->status }}'", false);
        $response->assertSee('status: currentFightStatus', false);
    }

    public function test_status_endpoint_returns_raw_unscaled_pool_totals(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $game = Game::create([
            'game_name' => 'pool-sabong',
            'display_name' => 'Pool Sabong',
            'plasada' => 5.00,
            'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        $event = Event::create([
            'game_id' => $game->id,
            'name' => 'Test Card',
            'status' => 'live',
            'draw_enabled' => true,
            'multiplier' => 3,
        ]);

        $fight = Fight::create([
            'event_id' => $event->id,
            'fight_number' => 1,
            'status' => 'open',
            'draw_enabled' => true,
        ]);

        $bettor = User::factory()->create();
        $bettor->assignRole('player');
        Wallet::create(['user_id' => $bettor->id, 'main_balance' => 1000]);

        Bet::create(['user_id' => $bettor->id, 'fight_id' => $fight->id, 'side' => 'meron', 'amount' => 100, 'status' => 'matched']);

        $response = $this->actingAs($bettor)->getJson(route('play.pool-fight.status', $fight));

        // The JSON endpoint intentionally returns raw amounts — display scaling
        // (by event.multiplier) is applied client-side, never server-side.
        $response->assertJson(['meron_pool' => 100]);
    }
}
