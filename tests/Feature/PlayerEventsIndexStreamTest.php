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
 * The /play lobby's live-event tiles show each event's static banner (its
 * own upload, or its game's default) rather than an autoplaying stream
 * preview — several autoplaying iframes on one grid was heavier than the
 * lobby needed, and the fight page itself already carries the live feed
 * once a player picks an event.
 */
class PlayerEventsIndexStreamTest extends TestCase
{
    use RefreshDatabase;

    private function player(): User
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        return $player;
    }

    private function game(): Game
    {
        return Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00, 'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
    }

    public function test_a_live_event_with_a_cockpit_feed_still_shows_its_banner_not_the_stream(): void
    {
        $player = $this->player();

        $cockpit = Cockpit::create(['name' => 'Ring 1', 'stream_url' => 'https://stream.example.com/ring1']);
        $event = Event::create([
            'game_id' => $this->game()->id, 'name' => 'Grand Derby', 'status' => 'live', 'draw_enabled' => true,
            'thumbnail_url' => 'https://banners.example.com/grand-derby.jpg',
        ]);
        Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $cockpit->id]);

        $response = $this->actingAs($player)->get(route('play.index'));

        $response->assertOk();
        $response->assertDontSee('<iframe', false);
        $response->assertSee('https://banners.example.com/grand-derby.jpg', false);
    }

    public function test_a_live_event_with_no_banner_of_its_own_falls_back_to_its_games_default_banner(): void
    {
        $player = $this->player();

        $game = $this->game();
        $game->update(['default_banner_url' => 'https://banners.example.com/default.jpg']);
        $event = Event::create(['game_id' => $game->id, 'name' => 'No Banner Card', 'status' => 'live', 'draw_enabled' => true]);
        Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        $response = $this->actingAs($player)->get(route('play.index'));

        $response->assertOk();
        $response->assertSee('https://banners.example.com/default.jpg', false);
    }

    public function test_a_live_event_with_no_banner_at_all_falls_back_to_the_placeholder(): void
    {
        $player = $this->player();

        $event = Event::create(['game_id' => $this->game()->id, 'name' => 'No Banner At All', 'status' => 'live', 'draw_enabled' => true]);
        Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        $response = $this->actingAs($player)->get(route('play.index'));

        $response->assertOk();
        $response->assertDontSee('<iframe', false);
    }
}
