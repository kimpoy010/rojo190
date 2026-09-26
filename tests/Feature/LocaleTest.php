<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    private function game(string $region): Game
    {
        return Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'region' => $region,
            'plasada' => 5.00, 'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
    }

    public function test_the_default_locale_is_english_for_the_philippines_region(): void
    {
        $this->game('philippines');

        $this->get(route('login'));

        $this->assertSame('en', App::getLocale());
    }

    public function test_the_default_locale_is_spanish_for_the_mexico_region(): void
    {
        $this->game('mexico');

        $this->get(route('login'));

        $this->assertSame('es', App::getLocale());
    }

    public function test_the_locale_switcher_overrides_the_region_default(): void
    {
        $this->game('mexico');

        $this->get(route('locale.switch', 'en'));
        $this->get(route('login'));

        $this->assertSame('en', App::getLocale());
    }

    public function test_the_switcher_choice_persists_across_requests_in_the_session(): void
    {
        $this->game('philippines');

        $this->get(route('locale.switch', 'es'));
        $response = $this->get(route('login'));

        $response->assertOk();
        $this->assertSame('es', App::getLocale());
    }

    public function test_an_invalid_locale_is_ignored(): void
    {
        $this->game('philippines');

        $response = $this->get('/locale/fr');

        $response->assertNotFound();
    }

    public function test_switching_locale_works_without_authentication(): void
    {
        $response = $this->get(route('locale.switch', 'es'));

        $response->assertRedirect();
        $this->assertSame('es', session('locale'));
    }

    public function test_login_page_renders_in_spanish_when_switched(): void
    {
        $this->game('philippines');

        $this->get(route('locale.switch', 'es'));
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Iniciar sesión');
    }

    public function test_declarator_error_messages_translate_to_spanish(): void
    {
        Role::firstOrCreate(['name' => 'declarator']);
        $this->game('mexico');
        $declarator = User::factory()->create();
        $declarator->assignRole('declarator');

        $event = \App\Models\Event::create([
            'game_id' => Game::first()->id, 'name' => 'Card', 'status' => 'upcoming', 'draw_enabled' => true,
        ]);

        $response = $this->actingAs($declarator)->post(route('declarator.events.fights.start-next', $event));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Solo un evento en vivo puede iniciar una nueva pelea.');
    }
}
