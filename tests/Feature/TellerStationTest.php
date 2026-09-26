<?php

namespace Tests\Feature;

use App\Models\RfidReader;
use App\Models\RfidTerminal;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TellerShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TellerStationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'teller']);
        Role::firstOrCreate(['name' => 'player']);
    }

    private function teller(): User
    {
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        return $teller;
    }

    private function player(string $rfidUid = 'TAG-1', float $balance = 500): User
    {
        $player = User::factory()->create(['rfid_uid' => $rfidUid]);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => $balance]);

        return $player;
    }

    public function test_an_identify_reader_tap_resolves_via_the_scan_api_without_a_wallet_action(): void
    {
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);
        RfidReader::create(['rfid_terminal_id' => $terminal->id, 'device_id' => 'AA:BB', 'role' => 'identify']);
        $player = $this->player();

        $response = $this->postJson('/api/rfid/scan', ['device_id' => 'AA:BB', 'tag_uid' => 'TAG-1'], [
            'Authorization' => "Bearer {$terminal->token}",
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertEquals(500, (float) $player->wallet->fresh()->main_balance);
    }

    public function test_instant_deposit_requires_an_open_shift(): void
    {
        $teller = $this->teller();
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);
        $player = $this->player();

        $response = $this->actingAs($teller)->post(route('teller.station.deposit', [$terminal, $player]), ['amount' => 100]);

        $response->assertRedirect(route('teller.shift.start'));
        $this->assertEquals(500, (float) $player->wallet->fresh()->main_balance);
    }

    public function test_instant_deposit_credits_immediately_and_counts_toward_the_shift(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 1000);
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);
        $player = $this->player();

        $response = $this->actingAs($teller)->post(route('teller.station.deposit', [$terminal, $player]), ['amount' => 300]);

        $response->assertRedirect(route('teller.station.show', $terminal));
        $this->assertEquals(800, (float) $player->wallet->fresh()->main_balance);

        $totals = $shift->fresh()->totals();
        $this->assertEquals(300, $totals['deposits']);
        $this->assertSame(1, $totals['deposit_count']);
    }

    public function test_instant_withdrawal_debits_the_full_available_balance_immediately(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 1000);
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);
        $player = $this->player(balance: 500);

        $response = $this->actingAs($teller)->post(route('teller.station.withdraw', [$terminal, $player]));

        $response->assertRedirect(route('teller.station.show', $terminal));
        $this->assertEquals(0, (float) $player->wallet->fresh()->main_balance);

        $totals = $shift->fresh()->totals();
        $this->assertEquals(500, $totals['withdrawals']);
    }

    public function test_instant_withdrawal_with_zero_balance_is_rejected(): void
    {
        $teller = $this->teller();
        app(TellerShiftService::class)->startShift($teller, 1000);
        $terminal = RfidTerminal::create(['name' => 'Window 1', 'token' => RfidTerminal::generateToken()]);
        $player = $this->player(balance: 0);

        $response = $this->actingAs($teller)->post(route('teller.station.withdraw', [$terminal, $player]));

        $response->assertRedirect(route('teller.station.show', $terminal));
        $response->assertSessionHas('error');
    }
}
