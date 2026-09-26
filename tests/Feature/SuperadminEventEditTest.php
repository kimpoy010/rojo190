<?php

namespace Tests\Feature;

use App\Models\Bet;
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

class SuperadminEventEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin']);
        Role::firstOrCreate(['name' => 'declarator']);
        Role::firstOrCreate(['name' => 'player']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function declarator(): User
    {
        $declarator = User::factory()->create();
        $declarator->assignRole('declarator');

        return $declarator;
    }

    private function event(): Event
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        return Event::create([
            'game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live',
            'draw_enabled' => true, 'multiplier' => 1,
        ]);
    }

    private function preset(string $streamUrl): CockpitPreset
    {
        $cockpit = Cockpit::create(['name' => 'Ring A', 'stream_url' => $streamUrl]);
        $preset = CockpitPreset::create(['name' => 'Main Setup']);
        $preset->cockpits()->attach($cockpit->id, ['position' => 0]);

        return $preset;
    }

    public function test_superadmin_can_set_a_cockpit_preset_on_an_existing_event(): void
    {
        $admin = $this->admin();
        $event = $this->event();
        $this->assertNull($event->cockpit_preset_id);
        $preset = $this->preset('https://example.com/stream.m3u8');

        $response = $this->actingAs($admin)->put(route('superadmin.events.update', $event), [
            'name' => $event->name,
            'cockpit_preset_id' => $preset->id,
            'multiplier' => 1,
            'draw_enabled' => 1,
        ]);

        $response->assertRedirect(route('declarator.events.show', $event));
        $this->assertSame($preset->id, $event->fresh()->cockpit_preset_id);
    }

    public function test_an_events_cockpit_preset_stream_appears_on_the_player_betting_page(): void
    {
        $admin = $this->admin();
        $event = $this->event();
        $preset = $this->preset('https://example.com/stream.m3u8');
        $this->actingAs($admin)->put(route('superadmin.events.update', $event), [
            'name' => $event->name,
            'cockpit_preset_id' => $preset->id,
            'multiplier' => 1,
            'draw_enabled' => 1,
        ]);

        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 500]);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertSee('https://example.com/stream.m3u8', false);
    }

    public function test_a_declarator_can_also_set_a_cockpit_preset_on_an_existing_event(): void
    {
        $declarator = $this->declarator();
        $event = $this->event();
        $preset = $this->preset('https://example.com/stream.m3u8');

        $response = $this->actingAs($declarator)->put(route('superadmin.events.update', $event), [
            'name' => $event->name,
            'cockpit_preset_id' => $preset->id,
            'multiplier' => 1,
            'draw_enabled' => 1,
        ]);

        $response->assertRedirect(route('declarator.events.show', $event));
        $this->assertSame($preset->id, $event->fresh()->cockpit_preset_id);
    }

    public function test_a_player_cannot_edit_an_event(): void
    {
        $player = User::factory()->create();
        $player->assignRole('player');
        $event = $this->event();

        $response = $this->actingAs($player)->get(route('superadmin.events.edit', $event));

        $response->assertForbidden();
    }

    public function test_a_declarator_can_create_an_event(): void
    {
        $declarator = $this->declarator();
        // The create form itself looks up the pool-sabong game by name, so
        // it needs one to exist even though this test doesn't use $this->event().
        Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        $this->actingAs($declarator)->get(route('superadmin.events.create'))->assertOk();

        $response = $this->actingAs($declarator)->post(route('superadmin.events.store'), [
            'name' => 'Declarator-Created Card',
            'multiplier' => 1,
            'draw_enabled' => 1,
        ]);

        $event = Event::where('name', 'Declarator-Created Card')->firstOrFail();
        $response->assertRedirect(route('declarator.events.show', $event));
    }

    public function test_the_events_list_shows_the_new_event_link_to_a_declarator(): void
    {
        $declarator = $this->declarator();

        $response = $this->actingAs($declarator)->get(route('declarator.events.index'));

        $response->assertOk();
        $response->assertSee(route('superadmin.events.create'), false);
    }

    public function test_unchecking_draw_enabled_on_an_event_also_disables_it_on_an_already_created_fight(): void
    {
        $admin = $this->admin();
        $event = $this->event();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'pending', 'draw_enabled' => true]);

        $this->actingAs($admin)->put(route('superadmin.events.update', $event), [
            'name' => $event->name,
            'multiplier' => 1,
            // draw_enabled omitted — an unchecked checkbox sends nothing.
        ]);

        $this->assertFalse($event->fresh()->draw_enabled);
        $this->assertFalse($fight->fresh()->draw_enabled);
    }

    public function test_unchecking_draw_enabled_does_not_touch_a_fight_that_already_has_draw_bets(): void
    {
        $admin = $this->admin();
        $event = $this->event();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 500]);
        Bet::create(['user_id' => $player->id, 'fight_id' => $fight->id, 'side' => 'draw', 'amount' => 50, 'status' => 'matched']);

        $this->actingAs($admin)->put(route('superadmin.events.update', $event), [
            'name' => $event->name,
            'multiplier' => 1,
            // draw_enabled omitted — an unchecked checkbox sends nothing.
        ]);

        $this->assertFalse($event->fresh()->draw_enabled);
        $this->assertTrue($fight->fresh()->draw_enabled);
    }

    public function test_a_player_cannot_create_an_event(): void
    {
        $player = User::factory()->create();
        $player->assignRole('player');

        $response = $this->actingAs($player)->get(route('superadmin.events.create'));

        $response->assertForbidden();
    }
}
