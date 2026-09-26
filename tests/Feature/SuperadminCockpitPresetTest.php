<?php

namespace Tests\Feature;

use App\Models\Cockpit;
use App\Models\CockpitPreset;
use App\Models\Event;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperadminCockpitPresetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    public function test_superadmin_can_create_a_preset_with_cockpits_in_order(): void
    {
        $admin = $this->admin();
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB = Cockpit::create(['name' => 'Ring B', 'stream_url' => 'https://example.com/b.m3u8']);

        $response = $this->actingAs($admin)->post(route('superadmin.cockpit-presets.store'), [
            'name' => 'Main Setup',
            'cockpit_ids' => [$ringB->id, $ringA->id],
        ]);

        $response->assertRedirect(route('superadmin.cockpit-presets.index'));

        $preset = CockpitPreset::firstOrFail();
        $this->assertSame('Main Setup', $preset->name);
        // Ring B was submitted first, so it's the preset's primary cockpit.
        $this->assertSame([$ringB->id, $ringA->id], $preset->cockpits->pluck('id')->all());
    }

    public function test_superadmin_can_update_a_presets_name_and_cockpits(): void
    {
        $admin = $this->admin();
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB = Cockpit::create(['name' => 'Ring B', 'stream_url' => 'https://example.com/b.m3u8']);
        $preset = CockpitPreset::create(['name' => 'Old Name']);
        $preset->cockpits()->attach($ringA->id, ['position' => 0]);

        $response = $this->actingAs($admin)->put(route('superadmin.cockpit-presets.update', $preset), [
            'name' => 'New Name',
            'cockpit_ids' => [$ringB->id],
        ]);

        $response->assertRedirect(route('superadmin.cockpit-presets.index'));
        $preset->refresh();
        $this->assertSame('New Name', $preset->name);
        $this->assertSame([$ringB->id], $preset->cockpits->pluck('id')->all());
    }

    public function test_superadmin_can_delete_a_preset(): void
    {
        $admin = $this->admin();
        $preset = CockpitPreset::create(['name' => 'To Delete']);

        $response = $this->actingAs($admin)->delete(route('superadmin.cockpit-presets.destroy', $preset));

        $response->assertRedirect(route('superadmin.cockpit-presets.index'));
        $this->assertModelMissing($preset);
    }

    public function test_deleting_a_preset_leaves_events_using_it_with_no_preset(): void
    {
        $admin = $this->admin();
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $preset = CockpitPreset::create(['name' => 'Main Setup']);
        $event = Event::create([
            'game_id' => $game->id, 'cockpit_preset_id' => $preset->id, 'name' => 'Card 1',
            'status' => 'live', 'draw_enabled' => true,
        ]);

        $this->actingAs($admin)->delete(route('superadmin.cockpit-presets.destroy', $preset));

        $this->assertNull($event->fresh()->cockpit_preset_id);
    }

    public function test_a_new_events_primary_stream_url_comes_from_its_presets_first_cockpit(): void
    {
        $cockpit = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $preset = CockpitPreset::create(['name' => 'Main Setup']);
        $preset->cockpits()->attach($cockpit->id, ['position' => 0]);

        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create([
            'game_id' => $game->id, 'cockpit_preset_id' => $preset->id, 'name' => 'Card 1',
            'status' => 'live', 'draw_enabled' => true,
        ]);

        $this->assertSame('https://example.com/a.m3u8', $event->primaryStreamUrl());
    }

    public function test_an_event_with_no_preset_has_no_primary_stream_url(): void
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create([
            'game_id' => $game->id, 'name' => 'Card 1', 'status' => 'live', 'draw_enabled' => true,
        ]);

        $this->assertNull($event->primaryStreamUrl());
    }
}
