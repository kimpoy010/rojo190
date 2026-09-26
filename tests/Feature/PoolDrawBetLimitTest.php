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
use Tests\TestCase;

class PoolDrawBetLimitTest extends TestCase
{
    use RefreshDatabase;

    private Fight $fight;

    private BettingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $game = Game::create([
            'game_name' => 'pool-sabong',
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

        $this->fight = Fight::create([
            'event_id' => $event->id,
            'fight_number' => 1,
            'status' => 'open',
            'draw_enabled' => true,
        ]);

        $this->service = app(BettingService::class);
    }

    private function bettor(float $balance = 1000): User
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'main_balance' => $balance]);

        return $user;
    }

    public function test_two_bettors_can_split_the_cap_exactly(): void
    {
        $this->service->placeBet($this->bettor(), $this->fight, 'draw', 60);
        $this->service->placeBet($this->bettor(), $this->fight, 'draw', 40);

        $this->assertEquals(100, (float) Bet::where('fight_id', $this->fight->id)->where('side', 'draw')->sum('amount'));
    }

    public function test_bet_that_reaches_the_cap_exactly_is_allowed(): void
    {
        $this->service->placeBet($this->bettor(), $this->fight, 'draw', 100);

        $this->assertEquals(100, (float) Bet::where('fight_id', $this->fight->id)->where('side', 'draw')->sum('amount'));
    }

    public function test_bet_once_cap_is_fully_used_is_rejected(): void
    {
        $this->service->placeBet($this->bettor(), $this->fight, 'draw', 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The draw pool limit of $100 has been reached.');

        $this->service->placeBet($this->bettor(), $this->fight, 'draw', 1);
    }

    public function test_bet_exceeding_remaining_capacity_is_rejected_entirely_not_partially_filled(): void
    {
        $this->service->placeBet($this->bettor(), $this->fight, 'draw', 80);

        try {
            $this->service->placeBet($this->bettor(), $this->fight, 'draw', 50);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('remaining in the draw pool', $e->getMessage());
        }

        // The rejected bet must leave no trace — pool stays at exactly 80.
        $this->assertEquals(80, (float) Bet::where('fight_id', $this->fight->id)->where('side', 'draw')->sum('amount'));
    }

    public function test_refunded_bets_do_not_count_toward_the_cap(): void
    {
        $bet = $this->service->placeBet($this->bettor(), $this->fight, 'draw', 100);
        $bet->update(['status' => 'refunded']);

        // Full cap should be available again since the only bet is refunded.
        $this->service->placeBet($this->bettor(), $this->fight, 'draw', 100);

        $this->assertEquals(100, (float) Bet::where('fight_id', $this->fight->id)->where('side', 'draw')->where('status', '!=', 'refunded')->sum('amount'));
    }
}
