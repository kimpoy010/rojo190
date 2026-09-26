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

class PoolBetSlipUxTest extends TestCase
{
    use RefreshDatabase;

    private function player(): User
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        return $player;
    }

    private function fight(string $status): Fight
    {
        $game = Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00, 'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);

        return Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => $status, 'draw_enabled' => true]);
    }

    public function test_the_amount_input_has_a_clear_button(): void
    {
        $player = $this->player();
        $fight = $this->fight('open');

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertSee('id="clear-bet-amount"', false);
    }

    public function test_placing_a_bet_no_longer_clears_the_amount_input_in_the_js(): void
    {
        $player = $this->player();
        $fight = $this->fight('open');

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        // A PHP feature test can't drive the JS itself, so this pins the
        // source instead: addMyBet() must not be immediately followed by
        // clearing the amount input, the way it used to be.
        $response->assertDontSee("addMyBet(side, amount);\n                document.getElementById('bet-amount').value = '';", false);
    }

    public function test_the_status_panel_shows_for_a_pending_fight(): void
    {
        $player = $this->player();
        $fight = $this->fight('pending');

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertDontSee('id="status-panel" class="bg-slate-800 rounded-xl px-4 py-6 text-center" hidden', false);
    }

    public function test_the_status_panel_shows_for_a_closed_fight(): void
    {
        $player = $this->player();
        $fight = $this->fight('closed');

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertDontSee('id="status-panel" class="bg-slate-800 rounded-xl px-4 py-6 text-center" hidden', false);
    }

    public function test_the_status_panel_is_hidden_for_an_open_fight(): void
    {
        $player = $this->player();
        $fight = $this->fight('open');

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertSee('id="status-panel" class="bg-slate-800 rounded-xl px-4 py-6 text-center" hidden', false);
    }
}
