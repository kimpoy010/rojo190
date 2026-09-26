<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlayerBetHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(string $name): Event
    {
        $game = Game::create([
            'game_name' => 'pool-sabong-'.uniqid(),
            'display_name' => 'Pool Sabong',
            'plasada' => 5.00,
            'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00,
            'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        return Event::create([
            'game_id' => $game->id,
            'name' => $name,
            'status' => 'live',
            'draw_enabled' => true,
            'multiplier' => 1,
        ]);
    }

    public function test_bet_history_paginates_at_10_rows_per_page(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $event = $this->makeEvent('Card A');
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => false]);

        $bettor = User::factory()->create();
        $bettor->assignRole('player');
        Wallet::create(['user_id' => $bettor->id, 'main_balance' => 1000]);

        // One more than a page's worth, so a second page must exist.
        for ($i = 0; $i < 11; $i++) {
            Bet::create(['user_id' => $bettor->id, 'fight_id' => $fight->id, 'side' => 'meron', 'amount' => 10, 'status' => 'matched']);
        }

        $response = $this->actingAs($bettor)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertViewHas('myBetHistory', function ($paginator) {
            return $paginator->count() === 10 && $paginator->total() === 11 && $paginator->hasMorePages();
        });
    }

    public function test_bet_history_can_be_filtered_to_one_event(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $eventA = $this->makeEvent('Card A');
        $eventB = $this->makeEvent('Card B');
        $fightA = Fight::create(['event_id' => $eventA->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => false]);
        $fightB = Fight::create(['event_id' => $eventB->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => false]);

        $bettor = User::factory()->create();
        $bettor->assignRole('player');
        Wallet::create(['user_id' => $bettor->id, 'main_balance' => 1000]);

        Bet::create(['user_id' => $bettor->id, 'fight_id' => $fightA->id, 'side' => 'meron', 'amount' => 10, 'status' => 'matched']);
        Bet::create(['user_id' => $bettor->id, 'fight_id' => $fightB->id, 'side' => 'wala', 'amount' => 20, 'status' => 'matched']);

        $response = $this->actingAs($bettor)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('play.bet-history', ['event_id' => $eventA->id]));

        $response->assertOk();
        $response->assertSee('Card A');
        $response->assertDontSee('Card B');
    }
}
