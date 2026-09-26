<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BettingService;
use App\Services\FightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperadminAccountingReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'superadmin']);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id, 'main_balance' => 1_000_000]);

        return $admin;
    }

    private function fight(array $fightAttrs = [], array $eventAttrs = []): Fight
    {
        $game = Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00, 'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);

        $event = Event::create(array_merge([
            'game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true,
        ], $eventAttrs));

        return Fight::create(array_merge([
            'event_id' => $event->id, 'fight_number' => 1, 'status' => 'closed', 'draw_enabled' => true,
        ], $fightAttrs));
    }

    private function bettor(float $balance = 1000): User
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'main_balance' => $balance]);

        return $user;
    }

    private function stake(User $user, Fight $fight, string $side, float $amount): Bet
    {
        $user->wallet->decrement('main_balance', $amount);

        return Bet::create([
            'user_id' => $user->id, 'fight_id' => $fight->id, 'side' => $side,
            'amount' => $amount, 'status' => 'matched',
        ]);
    }

    public function test_non_superadmin_cannot_view_either_report(): void
    {
        Role::firstOrCreate(['name' => 'teller']);
        $teller = User::factory()->create();
        $teller->assignRole('teller');

        $this->actingAs($teller)->get(route('superadmin.reports.accounting.events'))->assertForbidden();

        $fight = $this->fight();
        $this->actingAs($teller)->get(route('superadmin.reports.accounting.fight', $fight))->assertForbidden();
    }

    public function test_event_rollup_sums_every_bet_across_its_fights(): void
    {
        $admin = $this->admin();
        $event = Event::create([
            'game_id' => Game::firstOrCreate(['game_name' => 'pool-sabong'], [
                'display_name' => 'Pool Sabong', 'plasada' => 5.00, 'plasada_mode' => 'total_pool',
                'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
            ])->id,
            'name' => 'Grand Derby', 'status' => 'live', 'draw_enabled' => true,
        ]);

        // Both fights created up front — declareWinner() auto-creates a
        // backlog "next fight" too, and it would collide on fight_number
        // with a fight2 created afterward.
        $fight1 = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'closed', 'draw_enabled' => true]);
        $fight2 = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'closed', 'draw_enabled' => true]);

        $this->stake($this->bettor(), $fight1, 'meron', 100);
        $this->stake($this->bettor(), $fight1, 'wala', 100);
        app(FightService::class)->declareWinner($fight1, 'meron');
        app(BettingService::class)->settleBets($fight1->fresh(), 'meron');

        $this->stake($this->bettor(), $fight2, 'meron', 50);
        $this->stake($this->bettor(), $fight2, 'wala', 50);
        app(FightService::class)->declareWinner($fight2, 'wala');
        app(BettingService::class)->settleBets($fight2->fresh(), 'wala');

        $response = $this->actingAs($admin)->get(route('superadmin.reports.accounting.events'));

        $response->assertOk();
        $response->assertSee('Grand Derby');
        $response->assertSee('$300.00', false); // 200 + 100 staked across both fights
        $response->assertSee('4', false); // 4 bets total (bet_count column)
    }

    public function test_fight_ledger_lists_every_individual_bet(): void
    {
        $admin = $this->admin();
        $fight = $this->fight();

        $meronBettor = $this->bettor();
        $walaBettor = $this->bettor();

        $this->stake($meronBettor, $fight, 'meron', 100);
        $this->stake($walaBettor, $fight, 'wala', 6);

        app(FightService::class)->declareWinner($fight, 'meron');
        app(BettingService::class)->settleBets($fight->fresh(), 'meron');

        $response = $this->actingAs($admin)->get(route('superadmin.reports.accounting.fight', $fight));

        $response->assertOk();
        $response->assertSee($meronBettor->displayName());
        $response->assertSee($walaBettor->displayName());
        $response->assertSee('$100.00', false);
        $response->assertSee('$6.00', false);
    }

    public function test_a_counter_bet_ticket_shows_its_code_and_teller_in_the_ledger(): void
    {
        $admin = $this->admin();
        $fight = $this->fight(['status' => 'open']);

        Role::firstOrCreate(['name' => 'teller']);
        $teller = User::factory()->create(['username' => 'teller_juan']);
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);
        $shift = app(\App\Services\TellerShiftService::class)->startShift($teller, 5000);

        $ticket = app(BettingService::class)->placeCounterBet($teller, $shift, $fight, 'meron', 100);

        $response = $this->actingAs($admin)->get(route('superadmin.reports.accounting.fight', $fight));

        $response->assertOk();
        $response->assertSee($ticket->ticket_code);
        $response->assertSee('teller_juan');
    }

    public function test_non_superadmin_cannot_export_either_report(): void
    {
        Role::firstOrCreate(['name' => 'teller']);
        $teller = User::factory()->create();
        $teller->assignRole('teller');

        $this->actingAs($teller)->get(route('superadmin.reports.accounting.events.export'))->assertForbidden();

        $fight = $this->fight();
        $this->actingAs($teller)->get(route('superadmin.reports.accounting.fight.export', $fight))->assertForbidden();
    }

    public function test_events_export_streams_a_csv_of_every_matching_event(): void
    {
        $admin = $this->admin();
        $fight = $this->fight();

        $this->stake($this->bettor(), $fight, 'meron', 100);
        $this->stake($this->bettor(), $fight, 'wala', 6);
        app(FightService::class)->declareWinner($fight, 'meron');
        app(BettingService::class)->settleBets($fight->fresh(), 'meron');

        $response = $this->actingAs($admin)->get(route('superadmin.reports.accounting.events.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Fights', $csv);
        $this->assertStringContainsString('Paid out', $csv);
        $this->assertStringContainsString('Test Card', $csv);
        $this->assertStringContainsString('106.00', $csv);
    }

    public function test_fight_export_streams_a_csv_of_every_bet(): void
    {
        $admin = $this->admin();
        $fight = $this->fight();

        $meronBettor = $this->bettor();
        $this->stake($meronBettor, $fight, 'meron', 100);
        app(FightService::class)->declareWinner($fight, 'meron');
        app(BettingService::class)->settleBets($fight->fresh(), 'meron');

        $response = $this->actingAs($admin)->get(route('superadmin.reports.accounting.fight.export', $fight));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Bettor,Side,Amount,Status,Payout,Placed', $csv);
        $this->assertStringContainsString($meronBettor->displayName(), $csv);
        $this->assertStringContainsString('100.00', $csv);
    }
}
