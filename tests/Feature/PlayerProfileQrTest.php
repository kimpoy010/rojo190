<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlayerProfileQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'teller']);
    }

    private function player(string $username = 'juan'): User
    {
        $player = User::factory()->create(['username' => $username]);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        return $player;
    }

    private function teller(): User
    {
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        return $teller;
    }

    public function test_viewing_the_profile_page_generates_a_stable_code(): void
    {
        $player = $this->player();
        $this->assertNull($player->player_code);

        $this->actingAs($player)->get(route('play.profile'))->assertOk();

        $code = $player->fresh()->player_code;
        $this->assertNotEmpty($code);

        // Visiting again must not rotate the code — it's meant to be
        // scanned repeatedly.
        $this->actingAs($player)->get(route('play.profile'))->assertOk();
        $this->assertSame($code, $player->fresh()->player_code);
    }

    public function test_the_profile_page_has_a_logout_button_and_language_switcher(): void
    {
        $player = $this->player();

        $response = $this->actingAs($player)->get(route('play.profile'));

        $response->assertOk();
        $response->assertSee('action="'.route('logout').'"', false);
        $response->assertSee('href="'.route('locale.switch', 'en').'"', false);
        $response->assertSee('href="'.route('locale.switch', 'es').'"', false);
    }

    public function test_teller_can_open_the_link_form_from_a_players_qr_code(): void
    {
        $teller = $this->teller();
        $player = $this->player();
        $code = $player->profileCode();

        $response = $this->actingAs($teller)->get(route('teller.rfid.link', $code));

        $response->assertOk();
        $response->assertSee($player->displayName());
    }

    public function test_link_form_404s_for_an_unknown_code(): void
    {
        $teller = $this->teller();

        $response = $this->actingAs($teller)->get(route('teller.rfid.link', 'NOTAREALCODE'));

        $response->assertNotFound();
    }

    public function test_teller_can_link_a_card_via_the_scanned_player_code(): void
    {
        $teller = $this->teller();
        $player = $this->player();
        $code = $player->profileCode();

        $response = $this->actingAs($teller)->post(route('teller.rfid.store'), [
            'player_code' => $code,
            'tag_uid' => 'CARD-QR-1',
        ]);

        $response->assertRedirect(route('teller.rfid.index'));
        $this->assertSame('CARD-QR-1', $player->fresh()->rfid_uid);
    }

    public function test_manual_code_lookup_redirects_to_the_link_form(): void
    {
        $teller = $this->teller();
        $player = $this->player();
        $code = $player->profileCode();

        $response = $this->actingAs($teller)->post(route('teller.rfid.lookup'), ['code' => strtolower($code)]);

        $response->assertRedirect(route('teller.rfid.link', $player));
    }

    public function test_manual_code_lookup_with_unknown_code_shows_an_error(): void
    {
        $teller = $this->teller();

        $response = $this->actingAs($teller)->post(route('teller.rfid.lookup'), ['code' => 'NOPE']);

        $response->assertRedirect(route('teller.rfid.index'));
        $response->assertSessionHas('error');
    }
}
