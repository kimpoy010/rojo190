<?php

namespace Tests\Feature;

use App\Models\Bet;
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

class TellerTicketFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'teller']);
        Role::firstOrCreate(['name' => 'superadmin']);
    }

    private function teller(): User
    {
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        return $teller;
    }

    private function fight(): Fight
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id, 'main_balance' => 1_000_000]);

        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);

        return Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
    }

    public function test_writing_a_ticket_requires_an_open_shift(): void
    {
        $teller = $this->teller();
        $fight = $this->fight();

        $response = $this->actingAs($teller)->post(route('teller.tickets.store'), [
            'side' => 'meron',
            'amount' => 100,
        ]);

        $response->assertRedirect(route('teller.shift.start'));
        $this->assertDatabaseCount('bets', 0);
    }

    public function test_teller_can_write_a_ticket_and_see_the_receipt(): void
    {
        $teller = $this->teller();
        app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();

        $response = $this->actingAs($teller)->post(route('teller.tickets.store'), [
            'side' => 'meron',
            'amount' => 250,
        ]);

        $bet = Bet::first();
        $response->assertRedirect(route('teller.tickets.receipt', $bet));
        $this->assertEquals(250, (float) $bet->amount);
        $this->assertNull($bet->user_id);

        $receipt = $this->actingAs($teller)->get(route('teller.tickets.receipt', $bet));
        $receipt->assertOk();
        $receipt->assertSee($bet->ticket_code);
        $receipt->assertSee('id="printable-ticket"', false);
        $receipt->assertSee('window.print()', false);
        $receipt->assertSee("addEventListener('load'", false);
    }

    public function test_writing_a_ticket_via_ajax_returns_the_receipt_inline_for_the_pop_up(): void
    {
        $teller = $this->teller();
        app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();

        $response = $this->actingAs($teller)->postJson(route('teller.tickets.store'), [
            'side' => 'wala',
            'amount' => 300,
        ]);

        $bet = Bet::first();
        $response->assertOk();
        $response->assertJson(['success' => true, 'ticket_code' => $bet->ticket_code]);
        $response->assertJsonFragment(['success' => true]);
        $this->assertStringContainsString('id="printable-ticket"', $response->json('receipt_html'));
        $this->assertStringContainsString($bet->ticket_code, $response->json('receipt_html'));
        $this->assertEquals(300, (float) $bet->amount);
        $this->assertEquals(300, $response->json('walaPool'));
    }

    public function test_the_ticket_create_page_shows_pool_totals_and_payout_odds(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 400);

        $response = $this->actingAs($teller)->get(route('teller.tickets.create'));

        $response->assertOk();
        $response->assertViewHas('meronPool', 400.0);
        $response->assertViewHas('walaPool', 0.0);
        $response->assertSee('id="meron-pool"', false);
        $response->assertSee('id="meron-payout-pct"', false);
        $response->assertSee('$400', false);
    }

    public function test_the_ticket_status_endpoint_reports_live_pool_totals(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 200);
        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'wala', 100);

        $response = $this->actingAs($teller)->getJson(route('teller.tickets.status'));

        $response->assertOk();
        $response->assertJson([
            'open' => true,
            'fight_number' => 1,
            'meronPool' => 200,
            'walaPool' => 100,
        ]);
        $this->assertGreaterThan(0, $response->json('payouts.meron'));
    }

    public function test_the_ticket_status_endpoint_reports_closed_when_no_fight_is_open(): void
    {
        $teller = $this->teller();
        app(TellerShiftService::class)->startShift($teller, 5000);

        $response = $this->actingAs($teller)->getJson(route('teller.tickets.status'));

        $response->assertOk();
        $response->assertJson(['open' => false]);
    }

    public function test_writing_a_ticket_via_ajax_without_an_open_shift_returns_a_redirect_payload(): void
    {
        $teller = $this->teller();
        $fight = $this->fight();

        $response = $this->actingAs($teller)->postJson(route('teller.tickets.store'), [
            'side' => 'meron',
            'amount' => 100,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'redirect' => route('teller.shift.start')]);
        $this->assertDatabaseCount('bets', 0);
    }

    public function test_writing_a_ticket_via_ajax_with_no_active_event_returns_an_error_message(): void
    {
        $teller = $this->teller();
        app(TellerShiftService::class)->startShift($teller, 5000);

        $response = $this->actingAs($teller)->postJson(route('teller.tickets.store'), [
            'side' => 'meron',
            'amount' => 100,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'message' => 'No active event right now.']);
        $this->assertDatabaseCount('bets', 0);
    }

    public function test_the_ticket_create_page_includes_the_receipt_pop_up(): void
    {
        $teller = $this->teller();
        app(TellerShiftService::class)->startShift($teller, 5000);
        $this->fight();

        $response = $this->actingAs($teller)->get(route('teller.tickets.create'));

        $response->assertOk();
        $response->assertSee('id="receipt-modal"', false);
        $response->assertSee("fetch(form.action", false);
    }

    public function test_ticket_codes_are_sequential_zero_padded_numbers(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $betting = app(BettingService::class);

        $first = $betting->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        $second = $betting->placeCounterBet($teller, $shift, $fight, 'wala', 100);

        $this->assertMatchesRegularExpression('/^\d{10}$/', $first->ticket_code);
        $this->assertSame((int) $first->ticket_code + 1, (int) $second->ticket_code);
    }

    public function test_redeem_lookup_accepts_an_un_padded_numeric_code(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        $response = $this->actingAs($teller)->post(route('teller.tickets.lookup'), [
            'code' => (string) (int) $ticket->ticket_code,
        ]);

        $response->assertRedirect(route('teller.tickets.show', $ticket));
    }

    public function test_redeem_lookup_accepts_the_full_qr_url_a_handheld_scanner_types_out(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        $response = $this->actingAs($teller)->post(route('teller.tickets.lookup'), [
            'code' => route('teller.tickets.show', $ticket),
        ]);

        $response->assertRedirect(route('teller.tickets.show', $ticket));
    }

    public function test_the_ticket_create_page_is_a_two_column_layout_with_a_history_search_box(): void
    {
        $teller = $this->teller();
        app(TellerShiftService::class)->startShift($teller, 5000);
        $this->fight();

        $response = $this->actingAs($teller)->get(route('teller.tickets.create'));

        $response->assertOk();
        $response->assertSee('lg:grid-cols-[26rem_1fr]', false);
        $response->assertSee('id="history-search"', false);
    }

    public function test_writing_a_ticket_with_no_active_event_shows_an_error(): void
    {
        $teller = $this->teller();
        app(TellerShiftService::class)->startShift($teller, 5000);

        $response = $this->actingAs($teller)->post(route('teller.tickets.store'), [
            'side' => 'meron',
            'amount' => 100,
        ]);

        $response->assertRedirect(route('teller.tickets.create'));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('bets', 0);
    }

    public function test_the_ticket_form_resolves_the_live_event_without_a_picker(): void
    {
        $teller = $this->teller();
        app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();

        $response = $this->actingAs($teller)->get(route('teller.tickets.create'));

        $response->assertOk();
        $response->assertViewHas('event', fn ($event) => $event->id === $fight->event_id);
        $response->assertDontSee('name="event_id"', false);
    }

    public function test_manual_code_lookup_redirects_to_the_ticket_page(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $bet = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        $response = $this->actingAs($teller)->post(route('teller.tickets.lookup'), ['code' => strtolower($bet->ticket_code)]);

        $response->assertRedirect(route('teller.tickets.show', $bet));
    }

    public function test_redeeming_a_winning_ticket_via_http(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $betting = app(BettingService::class);

        $ticket = $betting->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        $walaBettor = User::factory()->create();
        Wallet::create(['user_id' => $walaBettor->id, 'main_balance' => 1000]);
        $betting->placeBet($walaBettor, $fight, 'wala', 100);
        $fight->update(['status' => 'closed']);
        $betting->settleBets($fight, 'meron');

        $response = $this->actingAs($teller)->post(route('teller.tickets.redeem', $ticket));

        $response->assertRedirect(route('teller.tickets.show', $ticket));
        $this->assertNotNull($ticket->fresh()->redeemed_at);

        // The ticket page reloads showing the redeemed state, which should
        // now offer a separate printable payout receipt for the bettor.
        $show = $this->actingAs($teller)->get(route('teller.tickets.show', $ticket));
        $show->assertOk();
        $show->assertSee('id="printable-receipt"', false);
        $show->assertSee(__('Print payout receipt'));
    }

    public function test_unredeemed_ticket_page_has_no_payout_receipt(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        $response = $this->actingAs($teller)->get(route('teller.tickets.show', $ticket));

        $response->assertOk();
        $response->assertDontSee('id="printable-receipt"', false);
    }

    public function test_redeeming_without_an_open_shift_is_blocked(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $betting = app(BettingService::class);

        $ticket = $betting->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        $walaBettor = User::factory()->create();
        Wallet::create(['user_id' => $walaBettor->id, 'main_balance' => 1000]);
        $betting->placeBet($walaBettor, $fight, 'wala', 100);
        $fight->update(['status' => 'closed']);
        $betting->settleBets($fight, 'meron');

        app(TellerShiftService::class)->endShift($shift, 5000);

        $response = $this->actingAs($teller)->post(route('teller.tickets.redeem', $ticket));

        $response->assertRedirect(route('teller.shift.start'));
        $this->assertNull($ticket->fresh()->redeemed_at);
    }
}
