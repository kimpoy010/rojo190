<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BettingService;
use App\Services\TellerShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperadminTellerCashFlowReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin']);
        Role::firstOrCreate(['name' => 'teller']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id, 'main_balance' => 1_000_000]);

        return $admin;
    }

    private function teller(string $username = 'teller_juan'): User
    {
        // displayName() prefers username over name — set that, not name,
        // so assertions against the report's rendered output match.
        $teller = User::factory()->create(['username' => $username]);
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        return $teller;
    }

    private function fight(): Fight
    {
        $game = Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00, 'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);

        return Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
    }

    public function test_non_superadmin_cannot_view_it(): void
    {
        $teller = $this->teller();

        $this->actingAs($teller)->get(route('superadmin.reports.teller-cash-flow'))->assertForbidden();
    }

    public function test_it_shows_stakes_collected_from_tickets_written(): void
    {
        $admin = $this->admin();
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();

        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'wala', 50);

        $response = $this->actingAs($admin)->get(route('superadmin.reports.teller-cash-flow'));

        $response->assertOk();
        $response->assertSee('teller_juan');
        $response->assertSee('$150.00', false); // 100 + 50 collected
    }

    public function test_a_voided_ticket_is_counted_but_never_added_to_stakes_collected(): void
    {
        $admin = $this->admin();
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();

        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        $superadmin = User::role('superadmin')->first();
        $superadmin->update(['pin' => '1234']);
        app(BettingService::class)->voidBet($ticket, $teller, $superadmin);

        $response = $this->actingAs($admin)->get(route('superadmin.reports.teller-cash-flow'));

        $response->assertOk();
        // The only ticket this teller wrote was voided — no stakes ever
        // counted as collected, but it still shows in the voided column.
        $response->assertDontSee('$100.00', false);
        $response->assertSee('teller_juan');
    }

    public function test_a_redeemed_ticket_shows_as_a_payout_for_the_redeeming_teller(): void
    {
        $admin = $this->admin();
        $writingTeller = $this->teller('writer_juan');
        $redeemingTeller = $this->teller('redeemer_pedro');

        $writeShift = app(TellerShiftService::class)->startShift($writingTeller, 5000);
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($writingTeller, $writeShift, $fight, 'meron', 100);

        $walaBettor = User::factory()->create();
        Wallet::create(['user_id' => $walaBettor->id, 'main_balance' => 1000]);
        app(BettingService::class)->placeBet($walaBettor, $fight, 'wala', 100);
        $fight->update(['status' => 'closed']);
        app(BettingService::class)->settleBets($fight, 'meron');

        $redeemShift = app(TellerShiftService::class)->startShift($redeemingTeller, 5000);
        app(BettingService::class)->redeemTicket($ticket->ticket_code, $redeemingTeller, $redeemShift);

        $response = $this->actingAs($admin)->get(route('superadmin.reports.teller-cash-flow'));

        $response->assertOk();
        $response->assertSee('writer_juan');
        $response->assertSee('redeemer_pedro');
    }

    public function test_date_filter_excludes_tickets_outside_the_range(): void
    {
        $admin = $this->admin();
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();

        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        $response = $this->actingAs($admin)->get(route('superadmin.reports.teller-cash-flow', [
            'from' => now()->addDay()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('No ticket activity in this period.');
    }

    public function test_non_superadmin_cannot_export_it(): void
    {
        $teller = $this->teller();

        $this->actingAs($teller)->get(route('superadmin.reports.teller-cash-flow.export'))->assertForbidden();
    }

    public function test_export_streams_a_csv_with_stakes_collected(): void
    {
        $admin = $this->admin();
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();

        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'wala', 50);

        $response = $this->actingAs($admin)->get(route('superadmin.reports.teller-cash-flow.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Stakes collected', $csv);
        $this->assertStringContainsString('Net cash flow', $csv);
        $this->assertStringContainsString('teller_juan', $csv);
        $this->assertStringContainsString('150.00', $csv);
    }
}
