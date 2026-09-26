<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Game;
use App\Models\User;
use App\Support\GameTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GameThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin']);
        Role::firstOrCreate(['name' => 'player']);
    }

    private function superadmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function makeGame(array $overrides = []): Game
    {
        return Game::create(array_merge([
            'game_name' => 'pool-sabong',
            'display_name' => 'Pool Sabong',
            'plasada' => 5,
            'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8,
            'max_draw_bet' => 100,
            'min_payout_threshold' => 130,
        ], $overrides));
    }

    public function test_game_defaults_to_philippines_region_and_theme(): void
    {
        $game = $this->makeGame()->fresh();

        $this->assertSame('philippines', $game->region);
        $this->assertSame('Meron', $game->theme()['meron']['label']);
        $this->assertSame('Wala', $game->theme()['wala']['label']);
    }

    public function test_game_theme_returns_mexico_colors_and_labels(): void
    {
        $game = $this->makeGame(['region' => 'mexico']);

        $theme = $game->theme();

        $this->assertSame('Rojo', $theme['meron']['label']);
        $this->assertSame('Verde', $theme['wala']['label']);
        $this->assertSame('bg-green-900', $theme['wala']['panel']);
    }

    public function test_game_theme_falls_back_to_philippines_for_unknown_region(): void
    {
        $theme = GameTheme::for('atlantis');

        $this->assertSame('Meron', $theme['meron']['label']);

        $theme = GameTheme::for(null);

        $this->assertSame('Meron', $theme['meron']['label']);
    }

    public function test_superadmin_can_switch_a_games_region_from_the_settings_page(): void
    {
        $admin = $this->superadmin();
        $game = $this->makeGame();

        $this->actingAs($admin)->put(route('superadmin.games.update', $game), [
            'display_name' => $game->display_name,
            'game_status' => 'active',
            'region' => 'mexico',
            'plasada' => 5,
            'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8,
            'max_draw_bet' => 100,
            'min_payout_threshold' => 130,
        ])->assertRedirect();

        $this->assertSame('mexico', $game->fresh()->region);
    }

    public function test_switching_region_resyncs_every_events_labels(): void
    {
        $admin = $this->superadmin();
        $game = $this->makeGame();
        $event = Event::create([
            'game_id' => $game->id,
            'name' => 'Card 1',
            'status' => 'live',
            'draw_enabled' => true,
        ]);

        $this->assertSame('Meron', $event->fresh()->label_meron);

        $this->actingAs($admin)->put(route('superadmin.games.update', $game), [
            'display_name' => $game->display_name,
            'game_status' => 'active',
            'region' => 'mexico',
            'plasada' => 5,
            'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8,
            'max_draw_bet' => 100,
            'min_payout_threshold' => 130,
        ]);

        $event->refresh();
        $this->assertSame('Rojo', $event->label_meron);
        $this->assertSame('Verde', $event->label_wala);
        $this->assertSame('Empate', $event->label_draw);
    }

    public function test_saving_settings_without_changing_region_leaves_event_labels_untouched(): void
    {
        $admin = $this->superadmin();
        $game = $this->makeGame();
        $event = Event::create([
            'game_id' => $game->id,
            'name' => 'Card 1',
            'status' => 'live',
            'draw_enabled' => true,
            'label_meron' => 'Custom Meron',
        ]);

        $this->actingAs($admin)->put(route('superadmin.games.update', $game), [
            'display_name' => $game->display_name,
            'game_status' => 'active',
            'region' => 'philippines',
            'plasada' => 7,
            'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8,
            'max_draw_bet' => 100,
            'min_payout_threshold' => 130,
        ]);

        $this->assertSame('Custom Meron', $event->fresh()->label_meron);
        $this->assertSame('7.00', $game->fresh()->plasada);
    }

    public function test_a_new_event_under_a_mexico_region_game_defaults_to_mexico_labels(): void
    {
        $admin = $this->superadmin();
        $game = $this->makeGame(['region' => 'mexico']);

        $this->actingAs($admin)->post(route('superadmin.events.store'), [
            'name' => 'Card 1',
            'draw_enabled' => true,
        ])->assertRedirect();

        $event = Event::where('game_id', $game->id)->firstOrFail();
        $this->assertSame('Rojo', $event->label_meron);
        $this->assertSame('Verde', $event->label_wala);
        $this->assertSame('Empate', $event->label_draw);
    }
}
