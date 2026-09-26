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

class CounterBetTicketTest extends TestCase
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

    private function fight(array $fightAttrs = [], array $gameAttrs = []): Fight
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id, 'main_balance' => 1_000_000]);

        $game = Game::create(array_merge([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ], $gameAttrs));

        $event = Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);

        return Fight::create(array_merge([
            'event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true,
        ], $fightAttrs));
    }

    public function test_writing_a_ticket_creates_a_walletless_bet_with_a_code(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();

        $bet = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 200);

        $this->assertNull($bet->user_id);
        $this->assertSame($teller->id, $bet->placed_by_teller_id);
        $this->assertSame($shift->id, $bet->placed_teller_shift_id);
        $this->assertNotEmpty($bet->ticket_code);
        $this->assertTrue($bet->isCounterBet());
    }

    public function test_ticket_stake_counts_as_shift_cash_in(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();

        app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 200);

        $totals = $shift->fresh()->totals();
        $this->assertEquals(200, $totals['ticket_stakes']);
        $this->assertSame(1, $totals['ticket_stake_count']);
        $this->assertEquals(5200, $totals['expected_cash']);
    }

    public function test_redeeming_before_settlement_is_rejected(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $betting = app(BettingService::class);

        $bet = $betting->placeCounterBet($teller, $shift, $fight, 'meron', 200);

        $this->expectException(\InvalidArgumentException::class);
        $betting->redeemTicket($bet->ticket_code, $teller, $shift);
    }

    public function test_winning_ticket_becomes_redeemable_and_pays_cash_not_a_wallet(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $betting = app(BettingService::class);

        $ticket = $betting->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        // A regular app bettor on wala so there's a real pool to settle against.
        $walaBettor = User::factory()->create();
        Wallet::create(['user_id' => $walaBettor->id, 'main_balance' => 1000]);
        $betting->placeBet($walaBettor, $fight, 'wala', 100);

        $fight->update(['status' => 'closed']);
        $betting->settleBets($fight, 'meron');

        $ticket->refresh();
        $this->assertSame('settled', $ticket->status);
        $this->assertGreaterThan(0, (float) $ticket->payout);
        $this->assertNull($ticket->redeemed_at);
        $this->assertTrue($ticket->isRedeemable());

        $payout = (float) $ticket->payout;
        $redeemed = $betting->redeemTicket($ticket->ticket_code, $teller, $shift);

        $this->assertNotNull($redeemed->redeemed_at);
        $this->assertSame($teller->id, $redeemed->redeemed_by_teller_id);
        $this->assertSame($shift->id, $redeemed->redeemed_teller_shift_id);

        $totals = $shift->fresh()->totals();
        $this->assertEquals($payout, $totals['ticket_payouts']);
        $this->assertSame(1, $totals['ticket_redeemed_count']);
    }

    public function test_a_losing_ticket_has_nothing_to_redeem(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $betting = app(BettingService::class);

        $ticket = $betting->placeCounterBet($teller, $shift, $fight, 'wala', 100);
        $meronBettor = User::factory()->create();
        Wallet::create(['user_id' => $meronBettor->id, 'main_balance' => 1000]);
        $betting->placeBet($meronBettor, $fight, 'meron', 100);

        $fight->update(['status' => 'closed']);
        $betting->settleBets($fight, 'meron');

        $ticket->refresh();
        $this->assertSame('settled', $ticket->status);
        $this->assertEquals(0, (float) $ticket->payout);
        $this->assertFalse($ticket->isRedeemable());

        $this->expectException(\InvalidArgumentException::class);
        $betting->redeemTicket($ticket->ticket_code, $teller, $shift);
    }

    public function test_a_ticket_cannot_be_redeemed_twice(): void
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

        $betting->redeemTicket($ticket->ticket_code, $teller, $shift);

        $this->expectException(\InvalidArgumentException::class);
        $betting->redeemTicket($ticket->ticket_code, $teller, $shift);
    }

    public function test_void_round_refunds_a_ticket_as_redeemable_for_the_full_stake(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        // total_pool mode with a high plasada can push the payout ratio below 1.0.
        $fight = $this->fight([], ['plasada_mode' => 'total_pool', 'plasada' => 60]);
        $betting = app(BettingService::class);

        $ticket = $betting->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        $walaBettor = User::factory()->create();
        Wallet::create(['user_id' => $walaBettor->id, 'main_balance' => 1000]);
        $betting->placeBet($walaBettor, $fight, 'wala', 5);

        $fight->update(['status' => 'closed']);
        $betting->settleBets($fight, 'meron');

        $fight->refresh();
        $this->assertSame('cancelled', $fight->status);

        $ticket->refresh();
        $this->assertSame('settled', $ticket->status);
        $this->assertEquals(100, (float) $ticket->payout);
        $this->assertTrue($ticket->isRedeemable());
    }

    public function test_draw_settlement_refunds_a_meron_ticket_and_pays_a_draw_ticket(): void
    {
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $betting = app(BettingService::class);

        $meronTicket = $betting->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        $drawTicket = $betting->placeCounterBet($teller, $shift, $fight, 'draw', 10);

        $fight->update(['status' => 'closed']);
        $betting->settleBets($fight, 'draw');

        $meronTicket->refresh();
        $this->assertEquals(100, (float) $meronTicket->payout); // refunded stake

        $drawTicket->refresh();
        $this->assertEquals(80, (float) $drawTicket->payout); // 10 * 8x multiplier
    }

    public function test_counter_bets_generate_no_commission(): void
    {
        Role::firstOrCreate(['name' => 'agent']);
        $teller = $this->teller();
        $shift = app(TellerShiftService::class)->startShift($teller, 5000);
        $fight = $this->fight();
        $betting = app(BettingService::class);

        $ticket = $betting->placeCounterBet($teller, $shift, $fight, 'meron', 100);
        $walaBettor = User::factory()->create();
        Wallet::create(['user_id' => $walaBettor->id, 'main_balance' => 1000]);
        $betting->placeBet($walaBettor, $fight, 'wala', 100);

        $fight->update(['status' => 'closed']);
        // Must not throw despite the ticket having no user/agent chain.
        $betting->settleBets($fight, 'meron');

        $this->assertDatabaseMissing('commission_logs', ['bet_id' => $ticket->id]);
    }
}
