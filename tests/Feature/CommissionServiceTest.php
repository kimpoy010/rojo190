<?php

namespace Tests\Feature;

use App\Models\AgentCommissionRate;
use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BettingService;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommissionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Fight $fight;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'agent']);
        Role::firstOrCreate(['name' => 'player']);

        $this->game = Game::create([
            'game_name' => 'pool-sabong',
            'display_name' => 'Pool Sabong',
            'plasada' => 5.00,
            'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        $event = Event::create([
            'game_id' => $this->game->id,
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
    }

    private function makeAgent(float $rate): User
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent');
        Wallet::create(['user_id' => $agent->id]);
        AgentCommissionRate::create(['agent_id' => $agent->id, 'game_id' => $this->game->id, 'commission_rate' => $rate]);

        return $agent;
    }

    public function test_player_with_no_agent_generates_no_commission(): void
    {
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        $bet = Bet::create(['user_id' => $player->id, 'fight_id' => $this->fight->id, 'side' => 'meron', 'amount' => 100, 'status' => 'matched']);

        $total = app(CommissionService::class)->distributeCommission($bet, 100);

        $this->assertSame(0.0, $total);
    }

    public function test_single_agent_earns_their_full_rate(): void
    {
        $agent = $this->makeAgent(2.00);
        $player = User::factory()->create(['agent_id' => $agent->id]);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        $bet = Bet::create(['user_id' => $player->id, 'fight_id' => $this->fight->id, 'side' => 'meron', 'amount' => 100, 'status' => 'matched']);

        $total = app(CommissionService::class)->distributeCommission($bet, 100);

        $this->assertEquals(2.00, $total);
        $this->assertEquals(2.00, (float) $agent->wallet->fresh()->commission_balance);
    }

    public function test_upline_only_earns_the_differential_over_downline_rate(): void
    {
        // L2 sub-agent at 1.5%, their L1 upline at 2.0% — the upline should
        // only earn the 0.5% differential, not another full 2%.
        $l1 = $this->makeAgent(2.00);
        $l2 = $this->makeAgent(1.50);
        $l2->update(['agent_id' => $l1->id]);

        $player = User::factory()->create(['agent_id' => $l2->id]);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        $bet = Bet::create(['user_id' => $player->id, 'fight_id' => $this->fight->id, 'side' => 'meron', 'amount' => 1000, 'status' => 'matched']);

        $total = app(CommissionService::class)->distributeCommission($bet, 1000);

        // L2: 1.5% of 1000 = 15.00; L1 differential: (2.0-1.5)% of 1000 = 5.00
        $this->assertEquals(15.00, (float) $l2->wallet->fresh()->commission_balance);
        $this->assertEquals(5.00, (float) $l1->wallet->fresh()->commission_balance);
        $this->assertEquals(20.00, $total);
    }

    public function test_upline_with_lower_rate_than_downline_earns_nothing(): void
    {
        // A downline agent should never be rate-capped retroactively by this
        // call — an upline whose own rate is lower than what their downline
        // already earns simply gets a zero (not negative) differential.
        $l1 = $this->makeAgent(1.00);
        $l2 = $this->makeAgent(1.50);
        $l2->update(['agent_id' => $l1->id]);

        $player = User::factory()->create(['agent_id' => $l2->id]);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id]);

        $bet = Bet::create(['user_id' => $player->id, 'fight_id' => $this->fight->id, 'side' => 'meron', 'amount' => 1000, 'status' => 'matched']);

        app(CommissionService::class)->distributeCommission($bet, 1000);

        $this->assertEquals(15.00, (float) $l2->wallet->fresh()->commission_balance);
        $this->assertEquals(0.00, (float) $l1->wallet->fresh()->commission_balance);
    }

    public function test_settlement_distributes_commission_on_both_winning_and_losing_bets(): void
    {
        Role::firstOrCreate(['name' => 'superadmin']);
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id, 'main_balance' => 1_000_000]);

        $agent = $this->makeAgent(2.00);

        $meronPlayer = User::factory()->create(['agent_id' => $agent->id]);
        $meronPlayer->assignRole('player');
        Wallet::create(['user_id' => $meronPlayer->id, 'main_balance' => 1000]);

        $walaPlayer = User::factory()->create(['agent_id' => $agent->id]);
        $walaPlayer->assignRole('player');
        Wallet::create(['user_id' => $walaPlayer->id, 'main_balance' => 1000]);

        Bet::create(['user_id' => $meronPlayer->id, 'fight_id' => $this->fight->id, 'side' => 'meron', 'amount' => 100, 'status' => 'matched']);
        Bet::create(['user_id' => $walaPlayer->id, 'fight_id' => $this->fight->id, 'side' => 'wala', 'amount' => 50, 'status' => 'matched']);

        $this->fight->update(['status' => 'closed']);
        app(BettingService::class)->settleBets($this->fight, 'meron');

        // Commission is earned on the actual staked amount of every meron/wala
        // bet, win or lose: 2% of 100 + 2% of 50 = 3.00.
        $this->assertEquals(3.00, (float) $agent->wallet->fresh()->commission_balance);
    }

    public function test_void_round_refund_generates_no_commission(): void
    {
        $agent = $this->makeAgent(2.00);
        $this->game->update(['plasada_mode' => 'total_pool', 'plasada' => 60]);

        $meronPlayer = User::factory()->create(['agent_id' => $agent->id]);
        $meronPlayer->assignRole('player');
        Wallet::create(['user_id' => $meronPlayer->id, 'main_balance' => 1000]);

        $walaPlayer = User::factory()->create(['agent_id' => $agent->id]);
        $walaPlayer->assignRole('player');
        Wallet::create(['user_id' => $walaPlayer->id, 'main_balance' => 1000]);

        Bet::create(['user_id' => $meronPlayer->id, 'fight_id' => $this->fight->id, 'side' => 'meron', 'amount' => 100, 'status' => 'matched']);
        Bet::create(['user_id' => $walaPlayer->id, 'fight_id' => $this->fight->id, 'side' => 'wala', 'amount' => 5, 'status' => 'matched']);

        $this->fight->update(['status' => 'closed']);
        app(BettingService::class)->settleBets($this->fight, 'meron');

        $this->fight->refresh();
        $this->assertSame('cancelled', $this->fight->status);
        $this->assertEquals(0.00, (float) $agent->wallet->fresh()->commission_balance);
    }
}
