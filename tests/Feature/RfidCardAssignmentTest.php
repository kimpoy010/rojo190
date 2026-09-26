<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RfidCardAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'teller']);
    }

    private function teller(): User
    {
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        return $teller;
    }

    private function player(string $username): User
    {
        $player = User::factory()->create(['username' => $username]);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        return $player;
    }

    public function test_teller_can_link_a_card_to_a_player(): void
    {
        $teller = $this->teller();
        $player = $this->player('juan');

        $response = $this->actingAs($teller)->post(route('teller.rfid.store'), [
            'username' => 'juan',
            'tag_uid' => 'CARD-001',
        ]);

        $response->assertRedirect(route('teller.rfid.index'));
        $this->assertSame('CARD-001', $player->fresh()->rfid_uid);
    }

    public function test_a_card_already_linked_to_someone_else_is_rejected(): void
    {
        $teller = $this->teller();
        $playerA = $this->player('juan');
        $playerB = $this->player('pedro');
        $playerA->update(['rfid_uid' => 'CARD-001']);

        $response = $this->actingAs($teller)->post(route('teller.rfid.store'), [
            'username' => 'pedro',
            'tag_uid' => 'CARD-001',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertNull($playerB->fresh()->rfid_uid);
    }

    public function test_unknown_username_is_rejected(): void
    {
        $teller = $this->teller();

        $response = $this->actingAs($teller)->post(route('teller.rfid.store'), [
            'username' => 'does-not-exist',
            'tag_uid' => 'CARD-002',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_teller_can_unlink_a_card(): void
    {
        $teller = $this->teller();
        $player = $this->player('juan');
        $player->update(['rfid_uid' => 'CARD-001']);

        $response = $this->actingAs($teller)->delete(route('teller.rfid.destroy', $player));

        $response->assertRedirect(route('teller.rfid.index'));
        $this->assertNull($player->fresh()->rfid_uid);
    }

    public function test_non_teller_cannot_access_rfid_card_management(): void
    {
        $player = $this->player('juan');

        $response = $this->actingAs($player)->get(route('teller.rfid.index'));

        $response->assertForbidden();
    }

    public function test_username_search_returns_matching_players(): void
    {
        $teller = $this->teller();
        $this->player('juan_delacruz');
        $this->player('juana_reyes');
        $this->player('pedro');

        $response = $this->actingAs($teller)->getJson(route('teller.rfid.search', ['q' => 'juan']));

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['username' => 'juan_delacruz']);
        $response->assertJsonFragment(['username' => 'juana_reyes']);
    }

    public function test_username_search_flags_players_who_already_have_a_card(): void
    {
        $teller = $this->teller();
        $player = $this->player('juan');
        $player->update(['rfid_uid' => 'CARD-001']);

        $response = $this->actingAs($teller)->getJson(route('teller.rfid.search', ['q' => 'juan']));

        $response->assertOk();
        $response->assertJsonFragment(['username' => 'juan', 'has_card' => true]);
    }

    public function test_username_search_with_empty_query_returns_nothing(): void
    {
        $teller = $this->teller();
        $this->player('juan');

        $response = $this->actingAs($teller)->getJson(route('teller.rfid.search', ['q' => '']));

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_username_search_is_teller_only(): void
    {
        $player = $this->player('juan');

        $response = $this->actingAs($player)->getJson(route('teller.rfid.search', ['q' => 'juan']));

        $response->assertForbidden();
    }

    public function test_lookup_by_code_accepts_the_full_profile_qr_url_a_handheld_scanner_types_out(): void
    {
        $teller = $this->teller();
        $player = $this->player('juan');
        $playerCode = $player->profileCode();

        $response = $this->actingAs($teller)->post(route('teller.rfid.lookup'), [
            'code' => route('teller.rfid.link', $player),
        ]);

        $response->assertRedirect(route('teller.rfid.link', $player));
        $this->assertNotEmpty($playerCode);
    }
}
