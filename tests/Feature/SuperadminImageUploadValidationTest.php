<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression for a security-review finding: event thumbnails and game
 * banners accepted Laravel's bare "image" rule, which allows SVG — a
 * format that can carry an embedded <script> and execute it if the
 * uploaded file is later opened directly (stored XSS). Both uploads now
 * restrict to raster formats only.
 */
class SuperadminImageUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin']);
        Storage::fake('public');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function game(): Game
    {
        return Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
    }

    public function test_event_thumbnail_rejects_an_svg_upload(): void
    {
        $admin = $this->admin();
        $this->game();

        $response = $this->actingAs($admin)->post(route('superadmin.events.store'), [
            'name' => 'Test Card',
            'multiplier' => 1,
            'draw_enabled' => 1,
            'thumbnail' => UploadedFile::fake()->create('malicious.svg', 10, 'image/svg+xml'),
        ]);

        $response->assertSessionHasErrors('thumbnail');
    }

    public function test_event_thumbnail_accepts_a_png_upload(): void
    {
        $admin = $this->admin();
        $this->game();

        $response = $this->actingAs($admin)->post(route('superadmin.events.store'), [
            'name' => 'Test Card',
            'multiplier' => 1,
            'draw_enabled' => 1,
            'thumbnail' => UploadedFile::fake()->image('banner.png'),
        ]);

        $response->assertSessionDoesntHaveErrors('thumbnail');
    }

    public function test_game_default_banner_rejects_an_svg_upload(): void
    {
        $admin = $this->admin();
        $game = $this->game();

        $response = $this->actingAs($admin)->put(route('superadmin.games.update', $game), [
            'display_name' => $game->display_name,
            'game_status' => 'active',
            'region' => 'philippines',
            'plasada' => 5,
            'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8,
            'max_draw_bet' => 100,
            'min_payout_threshold' => 130,
            'default_banner' => UploadedFile::fake()->create('malicious.svg', 10, 'image/svg+xml'),
        ]);

        $response->assertSessionHasErrors('default_banner');
    }
}
