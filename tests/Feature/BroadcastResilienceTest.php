<?php

namespace Tests\Feature;

use App\Events\BetPoolUpdated;
use App\Events\CashTransactionUpdated;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BettingService;
use App\Services\CashTransactionService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression coverage: an unreachable Reverb server must never turn a
 * successful action into a reported failure. Before this fix,
 * CashTransactionService::approve()/cancel()/expire() broadcast *inside*
 * their DB::transaction — a broadcast failure there rolled back money that
 * had already moved. BettingService::placeBet() broadcasts after its
 * transaction commits, so the bet itself always survived, but the failure
 * still bubbled up and made the request look failed to the caller.
 */
class BroadcastResilienceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Swap the container's 'events' dispatcher so dispatching $eventClass
     * throws, while everything else (including Laravel's own internal
     * events, e.g. Log firing MessageLogged) passes through untouched.
     */
    private function makeBroadcastFail(string $eventClass): void
    {
        $this->app->instance('events', new class($eventClass) implements Dispatcher
        {
            public function __construct(private string $eventClass) {}

            public function listen($events, $listener = null) {}

            public function hasListeners($eventName)
            {
                return false;
            }

            public function subscribe($subscriber) {}

            public function until($event, $payload = []) {}

            public function dispatch($event, $payload = [], $halt = false)
            {
                if ($event instanceof $this->eventClass) {
                    throw new \RuntimeException('Reverb unreachable: connection refused');
                }

                return null;
            }

            public function push($event, $payload = []) {}

            public function flush($event) {}

            public function forget($event) {}

            public function forgetPushed() {}
        });
    }

    public function test_placing_a_bet_succeeds_even_when_broadcasting_fails(): void
    {
        $game = Game::create([
            'game_name' => 'pool-sabong',
            'display_name' => 'Pool Sabong',
            'plasada' => 5.00,
            'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Test', 'status' => 'live', 'draw_enabled' => true]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        $player = User::factory()->create();
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        $this->makeBroadcastFail(BetPoolUpdated::class);

        // Must not throw — the bet was already committed before broadcasting
        // was attempted.
        $bet = app(BettingService::class)->placeBet($player, $fight, 'meron', 100);

        $this->assertSame('matched', $bet->status);
        $this->assertEquals(900, (float) $player->wallet->fresh()->main_balance);
        $this->assertDatabaseHas('bets', ['user_id' => $player->id, 'side' => 'meron', 'amount' => 100]);
    }

    public function test_approving_a_deposit_succeeds_even_when_broadcasting_fails(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'teller']);

        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 500]);

        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $cashService = app(CashTransactionService::class);
        $tx = $cashService->createDeposit($player, 250);

        $this->makeBroadcastFail(CashTransactionUpdated::class);

        // This is the critical case: approve() broadcasts *inside* its own
        // DB::transaction. Before the fix, a broadcast failure here rolled
        // back the wallet credit entirely, even though the teller had
        // already handed the money over in real life.
        $result = $cashService->approve($tx, $teller);

        $this->assertSame('completed', $result->status);
        $this->assertEquals(750, (float) $player->wallet->fresh()->main_balance);
    }
}
