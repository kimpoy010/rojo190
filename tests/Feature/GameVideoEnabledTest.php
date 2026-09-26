<?php

namespace Tests\Feature;

use App\Models\Cockpit;
use App\Models\CockpitPreset;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Game::video_enabled — a superadmin-controlled switch that hides the
 * live video and the floating "other fights" PiP panel on the player
 * betting page entirely, without touching per-fight cockpit assignment.
 */
class GameVideoEnabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin']);
        Role::firstOrCreate(['name' => 'player']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function player(): User
    {
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 500]);

        return $player;
    }

    private function gameWithPresetStream(bool $videoEnabled, string $streamUrl = 'https://example.com/stream.m3u8'): Game
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00, 'video_enabled' => $videoEnabled,
        ]);

        $cockpit = Cockpit::create(['name' => 'Ring A', 'stream_url' => $streamUrl]);
        $preset = CockpitPreset::create(['name' => 'Main Setup']);
        $preset->cockpits()->attach($cockpit->id, ['position' => 0]);

        Event::create([
            'game_id' => $game->id, 'cockpit_preset_id' => $preset->id, 'name' => 'Test Card',
            'status' => 'live', 'draw_enabled' => true, 'multiplier' => 1,
        ]);

        return $game;
    }

    public function test_a_new_game_defaults_to_video_enabled(): void
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        $this->assertTrue($game->fresh()->video_enabled);
    }

    public function test_superadmin_can_disable_video_from_the_game_settings_page(): void
    {
        $admin = $this->admin();
        $game = $this->gameWithPresetStream(true);

        $this->actingAs($admin)->put(route('superadmin.games.update', $game), [
            'display_name' => $game->display_name,
            'game_status' => 'active',
            'region' => 'philippines',
            'plasada' => 5,
            'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8,
            'max_draw_bet' => 100,
            'min_payout_threshold' => 130,
            // video_enabled omitted — an unchecked checkbox sends nothing.
        ])->assertRedirect();

        $this->assertFalse($game->fresh()->video_enabled);
    }

    public function test_the_betting_page_shows_the_stream_and_pip_panel_when_video_is_enabled(): void
    {
        $game = $this->gameWithPresetStream(true);
        $event = $game->events()->first();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $player = $this->player();

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertSee('id="live-stream"', false);
        $response->assertSee('id="pip-stack"', false);
        $response->assertSee('https://example.com/stream.m3u8', false);
    }

    public function test_the_betting_page_hides_the_stream_and_pip_panel_when_video_is_disabled(): void
    {
        $game = $this->gameWithPresetStream(false);
        $event = $game->events()->first();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $player = $this->player();

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertDontSee('id="live-stream"', false);
        $response->assertDontSee('id="pip-stack"', false);
        $response->assertDontSee('https://example.com/stream.m3u8', false);
    }

    public function test_the_status_poll_endpoint_omits_the_stream_when_video_is_disabled(): void
    {
        $game = $this->gameWithPresetStream(false);
        $event = $game->events()->first();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $player = $this->player();

        $response = $this->actingAs($player)->getJson(route('play.pool-fight.status', $fight));

        $response->assertOk();
        $response->assertJson(['main_stream_url' => null, 'pip' => []]);
    }

    public function test_the_status_poll_endpoint_includes_the_stream_when_video_is_enabled(): void
    {
        $game = $this->gameWithPresetStream(true);
        $event = $game->events()->first();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $player = $this->player();

        $response = $this->actingAs($player)->getJson(route('play.pool-fight.status', $fight));

        $response->assertOk();
        $response->assertJson(['main_stream_url' => 'https://example.com/stream.m3u8']);
    }
}
