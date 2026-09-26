<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CashTransactionService;
use App\Services\TellerShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TellerShiftTest extends TestCase
{
    use RefreshDatabase;

    private TellerShiftService $shifts;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'teller']);

        $this->shifts = app(TellerShiftService::class);
    }

    private function teller(): User
    {
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        return $teller;
    }

    private function player(float $balance = 1000): User
    {
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => $balance]);

        return $player;
    }

    public function test_starting_a_shift_records_the_counted_float(): void
    {
        $teller = $this->teller();

        $shift = $this->shifts->startShift($teller, 5000);

        $this->assertSame('open', $shift->status);
        $this->assertEquals(5000, (float) $shift->starting_cash);
        $this->assertNotNull($shift->started_at);
    }

    public function test_cannot_start_a_second_shift_while_one_is_open(): void
    {
        $teller = $this->teller();
        $this->shifts->startShift($teller, 5000);

        $this->expectException(\InvalidArgumentException::class);
        $this->shifts->startShift($teller, 1000);
    }

    public function test_negative_starting_cash_is_rejected(): void
    {
        $teller = $this->teller();

        $this->expectException(\InvalidArgumentException::class);
        $this->shifts->startShift($teller, -1);
    }

    public function test_ending_a_shift_computes_expected_cash_and_variance(): void
    {
        $teller = $this->teller();
        $shift = $this->shifts->startShift($teller, 5000);

        $player = $this->player();
        $cashService = app(CashTransactionService::class);

        $deposit = $cashService->createDeposit($player, 1000);
        $cashService->approve($deposit, $teller, $shift);

        $withdrawal = $cashService->createWithdrawal($player);
        $cashService->approve($withdrawal, $teller, $shift->fresh());

        // Expected cash = 5000 starting + 1000 deposit in - 2000 withdrawal out = 4000.
        $withdrawnAmount = (float) $withdrawal->fresh()->amount;
        $expected = 5000 + 1000 - $withdrawnAmount;

        // Teller counts exactly the expected amount — perfectly balanced.
        $closed = $this->shifts->endShift($shift->fresh(), $expected);

        $this->assertSame('closed', $closed->status);
        $this->assertEquals($expected, (float) $closed->expected_cash);
        $this->assertEquals(0.0, (float) $closed->variance);
        $this->assertNotNull($closed->ended_at);
    }

    public function test_ending_a_shift_short_records_a_negative_variance(): void
    {
        $teller = $this->teller();
        $shift = $this->shifts->startShift($teller, 5000);

        // Teller only counts 4900 but the system expects 5000 — a shortage.
        $closed = $this->shifts->endShift($shift, 4900);

        $this->assertEquals(5000, (float) $closed->expected_cash);
        $this->assertEquals(-100, (float) $closed->variance);
    }

    public function test_cannot_end_an_already_closed_shift(): void
    {
        $teller = $this->teller();
        $shift = $this->shifts->startShift($teller, 5000);
        $this->shifts->endShift($shift, 5000);

        $this->expectException(\InvalidArgumentException::class);
        $this->shifts->endShift($shift->fresh(), 5000);
    }

    public function test_dashboard_redirects_to_start_shift_when_none_is_open(): void
    {
        $teller = $this->teller();

        $response = $this->actingAs($teller)->get(route('teller.dashboard'));

        $response->assertRedirect(route('teller.shift.start'));
    }

    public function test_dashboard_shows_shift_totals_once_a_shift_is_open(): void
    {
        $teller = $this->teller();
        $this->shifts->startShift($teller, 5000);

        $response = $this->actingAs($teller)->get(route('teller.dashboard'));

        $response->assertOk();
        $response->assertSee('5,000.00');
    }

    public function test_dashboard_focuses_the_scan_or_enter_a_code_field_on_load(): void
    {
        $teller = $this->teller();
        $this->shifts->startShift($teller, 5000);

        $response = $this->actingAs($teller)->get(route('teller.dashboard'));

        $response->assertOk();
        $response->assertSee('id="teller-lookup-code"', false);
        $response->assertSee("getElementById('teller-lookup-code')?.focus()", false);
    }

    public function test_approving_a_transaction_without_an_open_shift_is_blocked(): void
    {
        $teller = $this->teller();
        $player = $this->player();

        $deposit = CashTransaction::create([
            'user_id' => $player->id,
            'type' => 'deposit',
            'amount' => 500,
            'code' => Str::upper(Str::random(12)),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->actingAs($teller)->post(route('teller.transactions.approve', $deposit));

        $response->assertRedirect(route('teller.shift.start'));
        $this->assertSame('pending', $deposit->fresh()->status);
    }

    public function test_approving_a_transaction_tags_it_with_the_open_shift(): void
    {
        $teller = $this->teller();
        $shift = $this->shifts->startShift($teller, 5000);
        $player = $this->player();

        $deposit = CashTransaction::create([
            'user_id' => $player->id,
            'type' => 'deposit',
            'amount' => 500,
            'code' => Str::upper(Str::random(12)),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($teller)->post(route('teller.transactions.approve', $deposit))->assertRedirect(route('teller.dashboard'));

        $this->assertSame($shift->id, $deposit->fresh()->teller_shift_id);
        $this->assertSame('completed', $deposit->fresh()->status);
    }

    public function test_a_teller_cannot_view_another_tellers_shift_report(): void
    {
        $teller = $this->teller();
        $shift = $this->shifts->startShift($teller, 5000);
        $this->shifts->endShift($shift, 5000);

        $otherTeller = $this->teller();

        $response = $this->actingAs($otherTeller)->get(route('teller.shift.report', $shift));

        $response->assertForbidden();
    }

    public function test_a_teller_cannot_export_another_tellers_shift_report(): void
    {
        $teller = $this->teller();
        $shift = $this->shifts->startShift($teller, 5000);
        $this->shifts->endShift($shift, 5000);

        $otherTeller = $this->teller();

        $response = $this->actingAs($otherTeller)->get(route('teller.shift.report.export', $shift));

        $response->assertForbidden();
    }

    public function test_shift_report_export_streams_a_csv_of_its_transactions(): void
    {
        $teller = $this->teller();
        $shift = $this->shifts->startShift($teller, 5000);

        $player = $this->player();
        $cashService = app(CashTransactionService::class);

        $deposit = $cashService->createDeposit($player, 1000);
        $cashService->approve($deposit, $teller, $shift);

        $response = $this->actingAs($teller)->get(route('teller.shift.report.export', $shift));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Date', $csv);
        $this->assertStringContainsString('Type', $csv);
        $this->assertStringContainsString('Deposit', $csv);
        $this->assertStringContainsString($player->displayName(), $csv);
        $this->assertStringContainsString('1000.00', $csv);
    }

    public function test_end_to_end_shift_flow_via_http(): void
    {
        $teller = $this->teller();

        $this->actingAs($teller)
            ->post(route('teller.shift.store'), ['starting_cash' => 5000])
            ->assertRedirect(route('teller.dashboard'));

        $shift = $this->shifts->currentShift($teller->fresh());
        $this->assertNotNull($shift);

        $response = $this->actingAs($teller)
            ->post(route('teller.shift.close'), ['actual_cash' => 5000]);

        $shift = $shift->fresh();
        $response->assertRedirect(route('teller.shift.report', $shift));
        $this->assertSame('closed', $shift->status);
        $this->assertEquals(0.0, (float) $shift->variance);
    }
}
