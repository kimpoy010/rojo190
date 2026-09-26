<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A fight's draw_enabled flag only ever gated whether players/tellers could
 * BET on a draw (see BettingService::placeBet/placeCounterBet) — it was
 * never meant to say the fight itself can't actually end in one. Declaring
 * (and re-declaring) a draw must work regardless of that flag; only actual
 * draw betting should stay restricted by it.
 */
class DeclareDrawWithoutDrawBettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'declarator']);
    }

    private function declarator(): User
    {
        $declarator = User::factory()->create();
        $declarator->assignRole('declarator');

        return $declarator;
    }

    private function liveEvent(): Event
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        return Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);
    }

    public function test_a_closed_fight_can_be_declared_a_draw_even_with_draw_betting_disabled(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'closed', 'draw_enabled' => false]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.declare', $fight), ['winner' => 'draw']);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame('declared', $fight->fresh()->status);
        $this->assertSame('draw', $fight->fresh()->winner);
    }

    public function test_a_declared_fight_can_be_redeclared_a_draw_even_with_draw_betting_disabled(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        $fight = Fight::create([
            'event_id' => $event->id, 'fight_number' => 1, 'status' => 'declared',
            'winner' => 'meron', 'declared_at' => now(), 'draw_enabled' => false,
        ]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.redeclare', $fight), ['winner' => 'draw']);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame('declared', $fight->fresh()->status);
        $this->assertSame('draw', $fight->fresh()->winner);
    }
}
