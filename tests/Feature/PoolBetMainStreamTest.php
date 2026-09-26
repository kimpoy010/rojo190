<?php

namespace Tests\Feature;

use App\Models\Cockpit;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A fight can go from "no cockpit assigned yet" to "cockpit assigned" while
 * a player already has the page open — the declarator opens bets and picks
 * a cockpit after the previous fight was declared, with no page reload in
 * between. The /status poll has to carry the stream URL for exactly this
 * reason: see main_stream_url below and applyMainStream() in
 * pool-betting.blade.php, which populates the (always-present-but-hidden)
 * #live-stream box once one becomes available instead of requiring a
 * manual refresh.
 */
class PoolBetMainStreamTest extends TestCase
{
    use RefreshDatabase;

    private function player(): User
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        return $player;
    }

    private function event(): Event
    {
        $game = Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        return Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);
    }

    public function test_status_reports_null_main_stream_url_when_the_fight_has_no_cockpit(): void
    {
        $player = $this->player();
        $fight = Fight::create(['event_id' => $this->event()->id, 'fight_number' => 1, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($player)->getJson(route('play.pool-fight.status', $fight));

        $response->assertOk();
        $response->assertJson(['main_stream_url' => null]);
    }

    public function test_status_reports_the_fights_own_cockpit_stream_once_assigned(): void
    {
        $player = $this->player();
        $ring = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $fight = Fight::create([
            'event_id' => $this->event()->id, 'fight_number' => 1, 'status' => 'open',
            'draw_enabled' => true, 'cockpit_id' => $ring->id,
        ]);

        $response = $this->actingAs($player)->getJson(route('play.pool-fight.status', $fight));

        $response->assertOk();
        $response->assertJson(['main_stream_url' => 'https://example.com/a.m3u8']);
    }

    public function test_status_falls_back_to_the_most_recently_finished_fights_cockpit(): void
    {
        $player = $this->player();
        $event = $this->event();
        $ring = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);

        Fight::create([
            'event_id' => $event->id, 'fight_number' => 1, 'status' => 'declared',
            'draw_enabled' => true, 'cockpit_id' => $ring->id, 'winner' => 'meron',
        ]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($player)->getJson(route('play.pool-fight.status', $fight));

        $response->assertOk();
        $response->assertJson(['main_stream_url' => 'https://example.com/a.m3u8']);
    }

    public function test_status_does_not_fall_back_to_a_still_in_play_fights_cockpit(): void
    {
        $player = $this->player();
        $event = $this->event();
        $ring = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);

        Fight::create([
            'event_id' => $event->id, 'fight_number' => 1, 'status' => 'open',
            'draw_enabled' => true, 'cockpit_id' => $ring->id,
        ]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($player)->getJson(route('play.pool-fight.status', $fight));

        $response->assertOk();
        $response->assertJson(['main_stream_url' => null]);
    }

    public function test_initial_page_render_omits_the_stream_box_when_theres_no_cockpit_yet(): void
    {
        $player = $this->player();
        $fight = Fight::create(['event_id' => $this->event()->id, 'fight_number' => 1, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        // Present but hidden, never omitted — see applyMainStream() in the
        // view, which needs the box in the DOM to reveal later.
        $content = $response->getContent();
        $this->assertStringContainsString('id="live-stream"', $content);
        $this->assertMatchesRegularExpression('/id="live-stream"[^>]*\bhidden\b/', $content);
    }
}
