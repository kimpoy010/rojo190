<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Game;
use App\Models\RfidReader;
use App\Models\RfidTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RfidTerminalManagementTest extends TestCase
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

    public function test_superadmin_can_create_a_terminal_with_a_unique_token(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('superadmin.rfid-terminals.store'), ['name' => 'Window 1'])
            ->assertRedirect(route('superadmin.rfid-terminals.index'));

        $terminal = RfidTerminal::first();
        $this->assertSame('Window 1', $terminal->name);
        $this->assertNotEmpty($terminal->token);
    }

    public function test_a_terminal_automatically_follows_whichever_event_is_live_with_no_setup(): void
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8, 'max_draw_bet' => 100, 'min_payout_threshold' => 130,
        ]);
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);

        $this->assertNull($terminal->activeEvent());

        $event = Event::create(['game_id' => $game->id, 'name' => 'Card 1', 'status' => 'live', 'draw_enabled' => true]);

        $this->assertSame($event->id, $terminal->activeEvent()->id);
    }

    public function test_the_rfid_terminals_index_shows_the_currently_live_event_with_no_picker(): void
    {
        $admin = $this->superadmin();
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8, 'max_draw_bet' => 100, 'min_payout_threshold' => 130,
        ]);
        Event::create(['game_id' => $game->id, 'name' => 'Card 1', 'status' => 'live', 'draw_enabled' => true]);
        RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);

        $response = $this->actingAs($admin)->get(route('superadmin.rfid-terminals.index'));

        $response->assertOk();
        $response->assertSee('Card 1');
        $response->assertDontSee('name="event_id"', false);
    }

    public function test_superadmin_can_regenerate_a_terminals_token(): void
    {
        $admin = $this->superadmin();
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => 'OLD-TOKEN']);

        $this->actingAs($admin)->post(route('superadmin.rfid-terminals.regenerate-token', $terminal))
            ->assertRedirect();

        $this->assertNotSame('OLD-TOKEN', $terminal->fresh()->token);
    }

    public function test_superadmin_can_toggle_a_terminal_off_and_on(): void
    {
        $admin = $this->superadmin();
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);

        $this->actingAs($admin)->post(route('superadmin.rfid-terminals.toggle', $terminal))->assertRedirect();
        $this->assertFalse($terminal->fresh()->is_active);

        $this->actingAs($admin)->post(route('superadmin.rfid-terminals.toggle', $terminal))->assertRedirect();
        $this->assertTrue($terminal->fresh()->is_active);
    }

    public function test_non_superadmin_cannot_manage_terminals(): void
    {
        $player = User::factory()->create();
        $player->assignRole('player');

        $response = $this->actingAs($player)->get(route('superadmin.rfid-terminals.index'));

        $response->assertForbidden();
    }

    public function test_superadmin_can_assign_a_role_and_label_to_a_reader(): void
    {
        $admin = $this->superadmin();
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);
        $reader = RfidReader::create(['rfid_terminal_id' => $terminal->id, 'device_id' => 'AA:BB:CC']);

        $this->actingAs($admin)->post(route('superadmin.rfid-terminals.readers.role', [$terminal, $reader]), [
            'role' => 'meron',
            'label' => 'Left pad',
        ])->assertRedirect();

        $reader->refresh();
        $this->assertSame('meron', $reader->role);
        $this->assertSame('Left pad', $reader->label);
    }

    public function test_superadmin_can_assign_the_balance_check_role(): void
    {
        $admin = $this->superadmin();
        $terminal = RfidTerminal::create(['name' => 'Lobby Kiosk', 'token' => RfidTerminal::generateToken()]);
        $reader = RfidReader::create(['rfid_terminal_id' => $terminal->id, 'device_id' => 'AA:BB:CC']);

        $this->actingAs($admin)->post(route('superadmin.rfid-terminals.readers.role', [$terminal, $reader]), [
            'role' => 'balance',
        ])->assertRedirect();

        $this->assertSame('balance', $reader->fresh()->role);
    }

    public function test_superadmin_can_unassign_a_readers_role(): void
    {
        $admin = $this->superadmin();
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);
        $reader = RfidReader::create(['rfid_terminal_id' => $terminal->id, 'device_id' => 'AA:BB:CC', 'role' => 'meron']);

        $this->actingAs($admin)->post(route('superadmin.rfid-terminals.readers.role', [$terminal, $reader]), [
            'role' => '',
        ])->assertRedirect();

        $this->assertNull($reader->fresh()->role);
    }

    public function test_a_reader_belonging_to_a_different_terminal_404s(): void
    {
        $admin = $this->superadmin();
        $terminalA = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);
        $terminalB = RfidTerminal::create(['name' => 'Window 2', 'token' => RfidTerminal::generateToken()]);
        $reader = RfidReader::create(['rfid_terminal_id' => $terminalA->id, 'device_id' => 'AA:BB:CC']);

        $response = $this->actingAs($admin)->post(route('superadmin.rfid-terminals.readers.role', [$terminalB, $reader]), [
            'role' => 'meron',
        ]);

        $response->assertNotFound();
    }

    public function test_superadmin_can_remove_a_reader(): void
    {
        $admin = $this->superadmin();
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);
        $reader = RfidReader::create(['rfid_terminal_id' => $terminal->id, 'device_id' => 'AA:BB:CC']);

        $this->actingAs($admin)->delete(route('superadmin.rfid-terminals.readers.destroy', [$terminal, $reader]))
            ->assertRedirect();

        $this->assertDatabaseMissing('rfid_readers', ['id' => $reader->id]);
    }
}
