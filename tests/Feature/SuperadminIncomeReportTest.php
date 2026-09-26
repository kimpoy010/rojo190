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

class SuperadminIncomeReportTest extends TestCase
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

    private function fight(array $fightAttrs = []): Fight
    {
        $game = Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong',
            'plasada' => 5.00,
            'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        $event = Event::create([
            'game_id' => $game->id,
            'name' => 'Test Card',
            'status' => 'live',
            'draw_enabled' => true,
        ]);

        return Fight::create(array_merge([
            'event_id' => $event->id,
            'fight_number' => 1,
            'status' => 'closed',
            'draw_enabled' => true,
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
            'user_id' => $user->id,
            'fight_id' => $fight->id,
            'side' => $side,
            'amount' => $amount,
            'status' => 'matched',
        ]);
    }

    public function test_non_superadmin_cannot_view_the_income_report(): void
    {
        $teller = User::factory()->create();
        Role::firstOrCreate(['name' => 'teller']);
        $teller->assignRole('teller');

        $response = $this->actingAs($teller)->get(route('superadmin.reports.income'));

        $response->assertForbidden();
    }

    public function test_it_shows_house_income_for_a_declared_fight(): void
    {
        $admin = $this->admin();
        $fight = $this->fight();

        $meronBettor = $this->bettor();
        $walaBettor = $this->bettor();
        $this->stake($meronBettor, $fight, 'meron', 100);
        $this->stake($walaBettor, $fight, 'wala', 6);

        app(FightService::class)->declareWinner($fight, 'meron');
        app(BettingService::class)->settleBets($fight->fresh(), 'meron');

        // meronNet = 100 + 6*0.95 = 105.7 → ratio 1.057 → floor(100*1.057) = 105 paid out.
        // Staked 106, paid out 105 → 1 kept as house income.
        $response = $this->actingAs($admin)->get(route('superadmin.reports.income'));

        $response->assertOk();
        $response->assertSee('$106.00', false);
        $response->assertSee('$105.00', false);
        $response->assertSee('$1.00', false);
    }

    public function test_a_cancelled_fully_refunded_fight_shows_zero_net_income(): void
    {
        $admin = $this->admin();
        $fight = $this->fight();
        $fight->event->game->update(['plasada_mode' => 'total_pool', 'plasada' => 60]);

        $meronBettor = $this->bettor();
        $walaBettor = $this->bettor();
        $this->stake($meronBettor, $fight, 'meron', 100);
        $this->stake($walaBettor, $fight, 'wala', 5);

        app(FightService::class)->declareWinner($fight, 'meron');
        app(BettingService::class)->settleBets($fight->fresh(), 'meron');

        $this->assertSame('cancelled', $fight->fresh()->status);

        $response = $this->actingAs($admin)->get(route('superadmin.reports.income'));

        $response->assertOk();
        $response->assertSee('$105.00', false); // staked
        // Fully refunded: paid out equals staked, net income 0.00 appears twice
        // (per-row and in the summary tile).
        $this->assertSame(2, substr_count($response->getContent(), '$0.00'));
    }

    public function test_event_filter_narrows_the_report(): void
    {
        $admin = $this->admin();
        $fightA = $this->fight();
        $bettor = $this->bettor();
        $this->stake($bettor, $fightA, 'meron', 50);
        app(FightService::class)->declareWinner($fightA, 'meron');
        app(BettingService::class)->settleBets($fightA->fresh(), 'meron');

        $fightB = $this->fight();
        $bettorB = $this->bettor();
        $this->stake($bettorB, $fightB, 'meron', 999);
        app(FightService::class)->declareWinner($fightB, 'meron');
        app(BettingService::class)->settleBets($fightB->fresh(), 'meron');

        $response = $this->actingAs($admin)->get(route('superadmin.reports.income', ['event_id' => $fightA->event_id]));

        $response->assertOk();
        $response->assertDontSee('$999.00', false);
    }

    public function test_non_superadmin_cannot_export_the_income_report(): void
    {
        $teller = User::factory()->create();
        Role::firstOrCreate(['name' => 'teller']);
        $teller->assignRole('teller');

        $this->actingAs($teller)->get(route('superadmin.reports.income.export'))->assertForbidden();
    }

    public function test_export_streams_a_csv_with_matching_rows_and_respects_filters(): void
    {
        $admin = $this->admin();
        $fightA = $this->fight();
        $bettor = $this->bettor();
        $this->stake($bettor, $fightA, 'meron', 50);
        app(FightService::class)->declareWinner($fightA, 'meron');
        app(BettingService::class)->settleBets($fightA->fresh(), 'meron');

        $fightB = $this->fight();
        $bettorB = $this->bettor();
        $this->stake($bettorB, $fightB, 'meron', 999);
        app(FightService::class)->declareWinner($fightB, 'meron');
        app(BettingService::class)->settleBets($fightB->fresh(), 'meron');

        $response = $this->actingAs($admin)->get(route('superadmin.reports.income.export', ['event_id' => $fightA->event_id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Fight #', $csv);
        $this->assertStringContainsString('Paid out', $csv);
        $this->assertStringContainsString('50.00', $csv);
        $this->assertStringNotContainsString('999.00', $csv);
    }
}
