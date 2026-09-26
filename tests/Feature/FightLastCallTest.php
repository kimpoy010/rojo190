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

class FightLastCallTest extends TestCase
{
    use RefreshDatabase;

    private function liveEvent(): Event
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        return Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);
    }

    public function test_declarator_can_move_an_open_fight_to_last_call(): void
    {
        Role::firstOrCreate(['name' => 'declarator']);
        $declarator = User::factory()->create();
        $declarator->assignRole('declarator');

        $event = $this->liveEvent();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.last-call', $fight));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame('last_call', $fight->fresh()->status);
    }

    public function test_last_call_cannot_be_called_on_a_fight_that_isnt_open(): void
    {
        Role::firstOrCreate(['name' => 'declarator']);
        $declarator = User::factory()->create();
        $declarator->assignRole('declarator');

        $event = $this->liveEvent();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.last-call', $fight));

        $response->assertStatus(422);
        $this->assertSame('pending', $fight->fresh()->status);
    }

    public function test_a_last_call_fight_can_still_be_closed(): void
    {
        Role::firstOrCreate(['name' => 'declarator']);
        $declarator = User::factory()->create();
        $declarator->assignRole('declarator');

        $event = $this->liveEvent();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'last_call', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.close', $fight));

        $response->assertOk();
        $this->assertSame('closed', $fight->fresh()->status);
    }

    public function test_closing_is_rejected_on_a_merely_open_fight_last_call_is_required_first(): void
    {
        Role::firstOrCreate(['name' => 'declarator']);
        $declarator = User::factory()->create();
        $declarator->assignRole('declarator');

        $event = $this->liveEvent();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.close', $fight));

        $response->assertStatus(422);
        $this->assertSame('open', $fight->fresh()->status);
    }

    public function test_players_can_still_place_bets_during_last_call(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $event = $this->liveEvent();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'last_call', 'draw_enabled' => true]);

        $bettor = User::factory()->create();
        $bettor->assignRole('player');
        Wallet::create(['user_id' => $bettor->id, 'main_balance' => 1000]);

        $response = $this->actingAs($bettor)->postJson(route('play.pool-bet', $fight), [
            'side' => 'meron',
            'amount' => 50,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('bets', ['fight_id' => $fight->id, 'user_id' => $bettor->id, 'amount' => 50]);
    }

    public function test_bets_are_rejected_once_a_fight_is_fully_closed(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $event = $this->liveEvent();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'closed', 'draw_enabled' => true]);

        $bettor = User::factory()->create();
        $bettor->assignRole('player');
        Wallet::create(['user_id' => $bettor->id, 'main_balance' => 1000]);

        $response = $this->actingAs($bettor)->postJson(route('play.pool-bet', $fight), [
            'side' => 'meron',
            'amount' => 50,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('bets', ['fight_id' => $fight->id, 'user_id' => $bettor->id]);
    }
}
