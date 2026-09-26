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

class PoolBetEventSwitcherTest extends TestCase
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

    private function liveEventWithOpenFight(string $name): Fight
    {
        $game = Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00, 'plasada_mode' => 'losing_side',
            'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => $name, 'status' => 'live', 'draw_enabled' => true]);

        return Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
    }

    public function test_the_switcher_is_hidden_when_no_other_event_is_live(): void
    {
        $player = $this->player();
        $fight = $this->liveEventWithOpenFight('Only Event');

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertDontSee('id="event-switcher"', false);
    }

    public function test_the_switcher_lists_every_other_live_event_and_links_to_its_entry_route(): void
    {
        $player = $this->player();
        $fightA = $this->liveEventWithOpenFight('Grand Derby');
        $fightB = $this->liveEventWithOpenFight('Weekend Card');

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fightA));

        $response->assertOk();
        $response->assertSee('id="event-switcher"', false);
        $response->assertSee('Weekend Card');
        $response->assertSee('href="'.route('play.events.enter', $fightB->event).'"', false);
    }

    public function test_the_current_event_gets_its_own_tab_too(): void
    {
        $player = $this->player();
        $fightA = $this->liveEventWithOpenFight('Grand Derby');
        $this->liveEventWithOpenFight('Weekend Card');

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fightA));

        $response->assertOk();
        $response->assertSee('Grand Derby');
        $response->assertSee('href="'.route('play.pool-fight', $fightA).'"', false);
    }

    public function test_a_non_live_event_never_appears_in_the_switcher(): void
    {
        $player = $this->player();
        $fight = $this->liveEventWithOpenFight('Live Event');

        $game = Game::first();
        Event::create(['game_id' => $game->id, 'name' => 'Upcoming Event', 'status' => 'upcoming', 'draw_enabled' => true]);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fight));

        $response->assertOk();
        $response->assertDontSee('Upcoming Event');
    }

    public function test_each_tab_shows_its_own_events_latest_fight_number_and_status_as_words(): void
    {
        $player = $this->player();
        $fightA = $this->liveEventWithOpenFight('Grand Derby'); // open, fight #1
        $fightB = $this->liveEventWithOpenFight('Weekend Card');
        $fightB->update(['status' => 'closed']);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fightA));

        $response->assertOk();
        $response->assertSee('data-event-id="'.$fightA->event_id.'"', false);
        $response->assertSee('data-event-id="'.$fightB->event_id.'"', false);
        $response->assertSeeInOrder(['Fight #1', 'OPEN']);
        $response->assertSeeInOrder(['Fight #1', 'CLOSED']);
    }
}
