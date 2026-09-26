<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\RfidReader;
use App\Models\RfidTerminal;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RfidScanTest extends TestCase
{
    use RefreshDatabase;

    private function terminalWithOpenFight(): array
    {
        $game = Game::create([
            'game_name' => 'pool-sabong',
            'display_name' => 'Pool Sabong',
            'plasada' => 5.00,
            'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Test', 'status' => 'live', 'draw_enabled' => true]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $terminal = RfidTerminal::create(['name' => 'Kiosk 1', 'token' => RfidTerminal::generateToken()]);

        return [$terminal, $fight];
    }

    private function player(?string $rfidUid = 'TAG123', float $balance = 1000): User
    {
        $player = User::factory()->create(['rfid_uid' => $rfidUid]);
        Wallet::create(['user_id' => $player->id, 'main_balance' => $balance]);

        return $player;
    }

    /**
     * Register a reader board already assigned a role — mirrors what a
     * superadmin does once in the admin UI after a board's first
     * (rejected) check-in tap.
     */
    private function assignedReader(RfidTerminal $terminal, string $role, string $deviceId = 'AA:BB:CC:00:11:22'): RfidReader
    {
        return RfidReader::create([
            'rfid_terminal_id' => $terminal->id,
            'device_id' => $deviceId,
            'role' => $role,
        ]);
    }

    private function scan(RfidTerminal $terminal, string $deviceId, string $tagUid)
    {
        return $this->postJson('/api/rfid/scan', ['device_id' => $deviceId, 'tag_uid' => $tagUid], [
            'Authorization' => "Bearer {$terminal->token}",
        ]);
    }

    public function test_scan_requires_a_valid_terminal_token(): void
    {
        $response = $this->postJson('/api/rfid/scan', ['device_id' => 'AA:BB', 'tag_uid' => 'ABC'], [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $response->assertStatus(401);
    }

    public function test_an_unknown_device_auto_registers_but_is_rejected_until_assigned(): void
    {
        [$terminal] = $this->terminalWithOpenFight();

        $response = $this->scan($terminal, 'AA:BB:CC:DD:EE:FF', 'TAG123');

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'This reader has not been assigned a role yet. Ask a superadmin to assign it under RFID Terminals.']);
        $this->assertDatabaseHas('rfid_readers', [
            'rfid_terminal_id' => $terminal->id,
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'role' => null,
        ]);
    }

    public function test_a_reader_re_checking_in_updates_last_seen_without_duplicating(): void
    {
        [$terminal] = $this->terminalWithOpenFight();

        $this->scan($terminal, 'AA:BB:CC:DD:EE:FF', 'TAG123');
        $this->scan($terminal, 'AA:BB:CC:DD:EE:FF', 'TAG123');

        $this->assertSame(1, RfidReader::where('device_id', 'AA:BB:CC:DD:EE:FF')->count());
        $this->assertNotNull(RfidReader::where('device_id', 'AA:BB:CC:DD:EE:FF')->first()->last_seen_at);
    }

    public function test_scan_rejects_an_unregistered_card(): void
    {
        [$terminal] = $this->terminalWithOpenFight();
        $this->assignedReader($terminal, 'meron');

        $response = $this->scan($terminal, 'AA:BB:CC:00:11:22', 'UNKNOWN');

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonFragment(['message' => 'Card not registered. See a teller to link this card to your account.']);
    }

    public function test_bet_tap_without_an_armed_amount_is_rejected(): void
    {
        [$terminal] = $this->terminalWithOpenFight();
        $this->assignedReader($terminal, 'meron');
        $this->player();

        $response = $this->scan($terminal, 'AA:BB:CC:00:11:22', 'TAG123');

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'No amount selected. Choose an amount on screen, then tap your card.']);
    }

    public function test_arming_an_amount_then_tapping_places_a_bet(): void
    {
        [$terminal, $fight] = $this->terminalWithOpenFight();
        $this->assignedReader($terminal, 'meron');
        $player = $this->player();

        $this->postJson(route('kiosk.arm', $terminal), ['context' => 'bet', 'amount' => 250])->assertOk();

        $response = $this->scan($terminal, 'AA:BB:CC:00:11:22', 'TAG123');

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('bets', ['user_id' => $player->id, 'fight_id' => $fight->id, 'side' => 'meron', 'amount' => 250]);
        $this->assertEquals(750, (float) $player->wallet->fresh()->main_balance);
    }

    public function test_armed_amount_is_consumed_by_one_tap_only(): void
    {
        [$terminal] = $this->terminalWithOpenFight();
        $this->assignedReader($terminal, 'meron', 'MERON-DEVICE');
        $this->assignedReader($terminal, 'wala', 'WALA-DEVICE');
        $this->player();

        $this->postJson(route('kiosk.arm', $terminal), ['context' => 'bet', 'amount' => 100])->assertOk();

        $this->scan($terminal, 'MERON-DEVICE', 'TAG123')->assertJson(['success' => true]);

        // Second tap (even on a different reader) with no new arm should
        // fail — the armed amount was already consumed.
        $second = $this->scan($terminal, 'WALA-DEVICE', 'TAG123');

        $second->assertStatus(422);
        $second->assertJsonFragment(['message' => 'No amount selected. Choose an amount on screen, then tap your card.']);
    }

    public function test_bet_tap_is_rejected_when_no_fight_is_open(): void
    {
        $terminal = RfidTerminal::create(['name' => 'Kiosk 1', 'token' => RfidTerminal::generateToken()]);
        $this->assignedReader($terminal, 'meron');
        $this->player();

        $this->postJson(route('kiosk.arm', $terminal), ['context' => 'bet', 'amount' => 100])->assertOk();

        $response = $this->scan($terminal, 'AA:BB:CC:00:11:22', 'TAG123');

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Betting is not open right now.']);
    }

    public function test_topup_tap_creates_a_pending_rfid_origin_deposit(): void
    {
        [$terminal] = $this->terminalWithOpenFight();
        $this->assignedReader($terminal, 'topup');
        $player = $this->player();

        $this->postJson(route('kiosk.arm', $terminal), ['context' => 'topup', 'amount' => 500])->assertOk();

        $response = $this->scan($terminal, 'AA:BB:CC:00:11:22', 'TAG123');

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('cash_transactions', [
            'user_id' => $player->id,
            'type' => 'deposit',
            'origin' => 'rfid',
            'amount' => 500,
            'status' => 'pending',
        ]);
        // Nothing is credited yet — a teller still has to approve it.
        $this->assertEquals(1000, (float) $player->wallet->fresh()->main_balance);
    }

    public function test_kiosk_status_endpoint_reports_open_fight_and_pools(): void
    {
        [$terminal, $fight] = $this->terminalWithOpenFight();

        $response = $this->getJson(route('kiosk.status', $terminal));

        $response->assertOk();
        $response->assertJson(['open' => true, 'fight_number' => $fight->fight_number]);
    }

    public function test_kiosk_status_reports_closed_when_no_event_is_live(): void
    {
        $terminal = RfidTerminal::create(['name' => 'Kiosk 1', 'token' => RfidTerminal::generateToken()]);

        $response = $this->getJson(route('kiosk.status', $terminal));

        $response->assertOk();
        $response->assertJson(['open' => false]);
    }

    public function test_balance_tap_reports_available_balance_without_touching_the_wallet(): void
    {
        [$terminal] = $this->terminalWithOpenFight();
        $this->assignedReader($terminal, 'balance');
        $player = $this->player(balance: 1500);

        $response = $this->scan($terminal, 'AA:BB:CC:00:11:22', 'TAG123');

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $response->assertJsonFragment(['wallet_balance' => 1500, 'available_balance' => 1500]);
        // A balance check is read-only — the tap must not move any money.
        $this->assertEquals(1500, (float) $player->wallet->fresh()->main_balance);
    }

    public function test_balance_tap_still_requires_a_registered_card(): void
    {
        [$terminal] = $this->terminalWithOpenFight();
        $this->assignedReader($terminal, 'balance');

        $response = $this->scan($terminal, 'AA:BB:CC:00:11:22', 'UNKNOWN');

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Card not registered. See a teller to link this card to your account.']);
    }

    public function test_balance_lookup_by_profile_code_works_without_a_card(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $terminal = RfidTerminal::create(['name' => 'Balance Kiosk', 'token' => RfidTerminal::generateToken()]);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 750]);
        $code = $player->profileCode();

        $response = $this->postJson(route('kiosk.balance.lookup', $terminal), ['code' => $code]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $response->assertJsonFragment(['wallet_balance' => 750, 'available_balance' => 750]);
    }

    public function test_balance_lookup_accepts_the_full_profile_qr_url_a_handheld_scanner_types_out(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'teller']);
        $terminal = RfidTerminal::create(['name' => 'Balance Kiosk', 'token' => RfidTerminal::generateToken()]);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 300]);
        $player->profileCode(); // ensure it exists before building the URL below

        $response = $this->postJson(route('kiosk.balance.lookup', $terminal), [
            'code' => route('teller.rfid.link', $player),
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $response->assertJsonFragment(['available_balance' => 300]);
    }

    public function test_balance_lookup_rejects_an_unknown_code(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $terminal = RfidTerminal::create(['name' => 'Balance Kiosk', 'token' => RfidTerminal::generateToken()]);

        $response = $this->postJson(route('kiosk.balance.lookup', $terminal), ['code' => 'NOPE123456']);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'No account found for that code.']);
    }

    public function test_the_balance_kiosk_page_loads_for_an_active_terminal(): void
    {
        $terminal = RfidTerminal::create(['name' => 'Balance Kiosk', 'token' => RfidTerminal::generateToken()]);

        $response = $this->get(route('kiosk.balance', $terminal));

        $response->assertOk();
        $response->assertSee('Tap your card to check your balance');
    }

    public function test_the_balance_kiosk_page_404s_for_a_disabled_terminal(): void
    {
        $terminal = RfidTerminal::create(['name' => 'Balance Kiosk', 'token' => RfidTerminal::generateToken(), 'is_active' => false]);

        $response = $this->get(route('kiosk.balance', $terminal));

        $response->assertNotFound();
    }

    public function test_disabled_terminal_cannot_authenticate_the_scan_api(): void
    {
        [$terminal] = $this->terminalWithOpenFight();
        $terminal->update(['is_active' => false]);

        $response = $this->scan($terminal, 'AA:BB:CC:00:11:22', 'TAG123');

        $response->assertStatus(401);
    }
}
