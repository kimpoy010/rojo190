<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AdminPinService;
use App\Services\BettingService;
use App\Services\TellerShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketVoidTest extends TestCase
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

    private function admin(string $pin = '1234'): User
    {
        $admin = User::factory()->create(['pin' => $pin]);
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id, 'main_balance' => 1_000_000]);

        return $admin;
    }

    private function fight(): Fight
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);

        return Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
    }

    public function test_admin_pin_service_finds_the_matching_superadmin(): void
    {
        $this->admin('1234');
        $other = $this->admin('5678');

        $found = app(AdminPinService::class)->findApprover('5678');

        $this->assertNotNull($found);
        $this->assertSame($other->id, $found->id);
    }

    public function test_admin_pin_service_returns_null_for_a_wrong_pin(): void
    {
        $this->admin('1234');

        $this->assertNull(app(AdminPinService::class)->findApprover('0000'));
    }

    public function test_voiding_a_ticket_requires_an_open_shift(): void
    {
        $teller = $this->teller();
        $this->admin();
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($teller, app(TellerShiftService::class)->startShift($teller, 5000), $fight, 'meron', 100);
        app(TellerShiftService::class)->endShift($teller->tellerShifts()->first(), 5000);

        $response = $this->actingAs($teller)->postJson(route('teller.tickets.void', $ticket), ['pin' => '1234']);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $this->assertSame('matched', $ticket->fresh()->status);
    }

    public function test_voiding_with_the_wrong_pin_is_rejected(): void
    {
        $teller = $this->teller();
        $this->admin('1234');
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        $response = $this->actingAs($teller)->postJson(route('teller.tickets.void', $ticket), ['pin' => '9999']);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'message' => 'Incorrect admin PIN.']);
        $this->assertSame('matched', $ticket->fresh()->status);
    }

    /**
     * A 4-digit PIN (10,000 possibilities) is brute-forceable fast at a
     * high per-minute rate — this is the burst cap that closes that off.
     * A wrong guess never changes the ticket's state, so hammering the
     * same ticket repeatedly is safe here.
     */
    public function test_repeated_wrong_pin_attempts_are_throttled(): void
    {
        $teller = $this->teller();
        $this->admin('1234');
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($teller)
                ->postJson(route('teller.tickets.void', $ticket), ['pin' => '9999'])
                ->assertStatus(422);
        }

        $sixth = $this->actingAs($teller)->postJson(route('teller.tickets.void', $ticket), ['pin' => '9999']);

        $sixth->assertStatus(429);
    }

    public function test_voiding_with_the_correct_pin_pulls_the_ticket_from_the_pool(): void
    {
        $teller = $this->teller();
        $admin = $this->admin('1234');
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'wala', 50);

        $response = $this->actingAs($teller)->postJson(route('teller.tickets.void', $ticket), ['pin' => '1234']);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $fresh = $ticket->fresh();
        $this->assertSame('voided', $fresh->status);
        $this->assertSame($teller->id, $fresh->voided_by_teller_id);
        $this->assertSame($admin->id, $fresh->voided_by_admin_id);
        $this->assertNotNull($fresh->voided_at);

        // Pool totals recalculate immediately — the voided stake is gone.
        $status = $this->actingAs($teller)->getJson(route('teller.tickets.status'));
        $status->assertJson(['meronPool' => 0, 'walaPool' => 50]);

        // ...and it no longer counts as cash the teller is holding.
        $this->assertSame(50.0, $shift->fresh()->totals()['ticket_stakes']);
    }

    public function test_a_voided_ticket_cannot_be_voided_again(): void
    {
        $teller = $this->teller();
        $this->admin('1234');
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        app(BettingService::class)->voidBet($ticket, $teller, $this->admin('5555'));

        $response = $this->actingAs($teller)->postJson(route('teller.tickets.void', $ticket), ['pin' => '1234']);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'message' => 'This ticket has already been settled or voided.']);
    }

    public function test_a_ticket_cannot_be_voided_once_the_fight_is_declared(): void
    {
        $teller = $this->teller();
        $this->admin('1234');
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $betting = app(BettingService::class);
        $ticket = $betting->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        $walaBettor = User::factory()->create();
        Wallet::create(['user_id' => $walaBettor->id, 'main_balance' => 1000]);
        $betting->placeBet($walaBettor, $fight, 'wala', 100);
        $fight->update(['status' => 'closed']);
        $betting->settleBets($fight, 'meron');

        $response = $this->actingAs($teller)->postJson(route('teller.tickets.void', $ticket), ['pin' => '1234']);

        $response->assertStatus(422);
    }

    public function test_history_is_sorted_by_fight_number_and_shows_voided_status(): void
    {
        $teller = $this->teller();
        $this->admin('1234');
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $betting = app(BettingService::class);

        $fight1 = $this->fight();
        $event = $fight1->event;
        $fight2 = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'open', 'draw_enabled' => true]);

        $older = $betting->placeCounterBet($teller, $shift, $fight1, 'meron', 100);
        $newer = $betting->placeCounterBet($teller, $shift, $fight2, 'wala', 200);
        $betting->voidBet($older, $teller, $this->admin('5555'));

        $response = $this->actingAs($teller)->get(route('teller.tickets.create'));

        $response->assertOk();
        $response->assertSeeInOrder([$newer->ticket_code, $older->ticket_code]);
        $response->assertSee('Voided');
    }

    public function test_reprinting_a_ticket_via_ajax_returns_its_receipt_html(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        $response = $this->actingAs($teller)->getJson(route('teller.tickets.receipt', $ticket));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertStringContainsString($ticket->ticket_code, $response->json('receipt_html'));
    }
}
