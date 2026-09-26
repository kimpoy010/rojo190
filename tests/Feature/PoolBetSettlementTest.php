<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PoolBetSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function makeFight(array $gameAttrs = [], array $eventAttrs = [], array $fightAttrs = []): Fight
    {
        Role::firstOrCreate(['name' => 'superadmin']);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id, 'main_balance' => 1_000_000]);

        $game = Game::create(array_merge([
            'game_name' => 'pool-sabong',
            'display_name' => 'Pool Sabong',
            'plasada' => 5.00,
            'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ], $gameAttrs));

        $event = Event::create(array_merge([
            'game_id' => $game->id,
            'name' => 'Test Card',
            'status' => 'live',
            'draw_enabled' => true,
        ], $eventAttrs));

        return Fight::create(array_merge([
            'event_id' => $event->id,
            'fight_number' => 1,
            'status' => 'closed',
            'draw_enabled' => true,
        ], $fightAttrs));
    }

    private function makeBettor(float $balance = 1000): User
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'main_balance' => $balance]);

        return $user;
    }

    /**
     * Stake a bet the way BettingService::placeBet would — debiting the wallet
     * and creating a matched bet row — without requiring the fight to be open
     * (these tests exercise settlement, which only runs on closed fights).
     */
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

    public function test_winning_bet_is_paid_in_full_even_when_payout_is_tiny(): void
    {
        $fight = $this->makeFight();

        $meronBettor = $this->makeBettor();
        $walaBettor = $this->makeBettor();

        $this->stake($meronBettor, $fight, 'meron', 100);
        $this->stake($walaBettor, $fight, 'wala', 6);

        app(BettingService::class)->settleBets($fight, 'meron');

        // meronNet = 100 + 6*0.95 = 105.7 → ratio 1.057 → floor(100*1.057) = 105
        $bet = Bet::where('user_id', $meronBettor->id)->first();
        $this->assertSame('settled', $bet->status);
        $this->assertEquals(105, (int) $bet->payout);
        $this->assertEquals(1000 - 100 + 105, (float) $meronBettor->wallet->fresh()->main_balance);
    }

    public function test_lopsided_pool_voids_the_round_and_refunds_everyone(): void
    {
        $fight = $this->makeFight();

        $meronBettor = $this->makeBettor();
        $walaBettor = $this->makeBettor();

        // meron wins but wala pool is so much larger that meron's payout ratio would be < 1.0
        // is impossible under losing_side mode (winner always gets stake back at minimum) —
        // use total_pool mode where a large favourite pool can push the ratio below 1.
        $fight->event->game->update(['plasada_mode' => 'total_pool', 'plasada' => 60]);

        $this->stake($meronBettor, $fight, 'meron', 100);
        $this->stake($walaBettor, $fight, 'wala', 5);

        app(BettingService::class)->settleBets($fight, 'meron');

        $fight->refresh();
        $this->assertSame('cancelled', $fight->status);
        $this->assertNull($fight->winner);

        $this->assertSame('refunded', Bet::where('user_id', $meronBettor->id)->first()->status);
        $this->assertSame('refunded', Bet::where('user_id', $walaBettor->id)->first()->status);
        $this->assertEquals(1000, (float) $meronBettor->wallet->fresh()->main_balance);
        $this->assertEquals(1000, (float) $walaBettor->wallet->fresh()->main_balance);
    }

    public function test_draw_refunds_meron_and_wala_and_pays_draw_bettors_a_fixed_multiplier(): void
    {
        $fight = $this->makeFight();

        $meronBettor = $this->makeBettor();
        $drawBettor = $this->makeBettor();

        $this->stake($meronBettor, $fight, 'meron', 100);
        $this->stake($drawBettor, $fight, 'draw', 10);

        app(BettingService::class)->settleBets($fight, 'draw');

        $this->assertEquals(1000, (float) $meronBettor->wallet->fresh()->main_balance);
        // draw pays 8x flat: 10 * 8 = 80 payout, net +70 from the 1000 - 10 stake.
        $this->assertEquals(1000 - 10 + 80, (float) $drawBettor->wallet->fresh()->main_balance);
    }

    public function test_settlement_is_idempotent(): void
    {
        $fight = $this->makeFight();
        $bettor = $this->makeBettor();
        $this->stake($bettor, $fight, 'meron', 100);

        $service = app(BettingService::class);
        $service->settleBets($fight, 'meron');
        $balanceAfterFirst = $bettor->wallet->fresh()->main_balance;

        $service->settleBets($fight, 'meron');
        $balanceAfterSecond = $bettor->wallet->fresh()->main_balance;

        $this->assertEquals((float) $balanceAfterFirst, (float) $balanceAfterSecond);
    }
}
