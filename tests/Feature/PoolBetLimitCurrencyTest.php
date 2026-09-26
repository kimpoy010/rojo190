<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression for a security-review finding: the bet-limit-exceeded
 * message hardcoded a literal "$" and bypassed __() entirely, so a
 * Mexico-region player saw "$" instead of "Mex$" and the string couldn't
 * be translated to Spanish. See PoolBetController::store().
 */
class PoolBetLimitCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'player']);
    }

    private function fightWithBetLimit(string $region, float $betLimit): Fight
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'region' => $region,
            'plasada' => 5.00, 'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);

        $event = Event::create([
            'game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live',
            'draw_enabled' => true, 'multiplier' => 1, 'bet_limit' => $betLimit,
        ]);

        return Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
    }

    public function test_mexico_region_bet_limit_message_shows_mex_dollar_sign(): void
    {
        $fight = $this->fightWithBetLimit('mexico', 500);
        $bettor = User::factory()->create();
        $bettor->assignRole('player');
        Wallet::create(['user_id' => $bettor->id, 'main_balance' => 10000]);

        $response = $this->actingAs($bettor)->postJson(route('play.pool-bet', $fight), [
            'side' => 'meron',
            'amount' => 600,
        ]);

        $response->assertStatus(422);
        // Mexico defaults to the Spanish locale (see SetLocale) — this is
        // also proof the message now goes through __() at all, unlike the
        // hardcoded English string it replaced.
        $response->assertJsonFragment(['message' => 'La apuesta supera el límite máximo de Mex$500.']);
    }

    public function test_philippines_region_bet_limit_message_keeps_plain_dollar_sign(): void
    {
        $fight = $this->fightWithBetLimit('philippines', 500);
        $bettor = User::factory()->create();
        $bettor->assignRole('player');
        Wallet::create(['user_id' => $bettor->id, 'main_balance' => 10000]);

        $response = $this->actingAs($bettor)->postJson(route('play.pool-bet', $fight), [
            'side' => 'meron',
            'amount' => 600,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Bet exceeds the maximum limit of $500.']);
    }
}
