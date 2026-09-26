<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\Cockpit;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The PiP stack is scoped entirely to fights the player has an unsettled
 * bet riding on — same event or a different one, doesn't matter — never
 * just "another live event's current fight" with no bet behind it.
 */
class PoolBetPipTest extends TestCase
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

    private function event(string $name = 'Test Card'): Event
    {
        $game = Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);

        return Event::create(['game_id' => $game->id, 'name' => $name, 'status' => 'live', 'draw_enabled' => true]);
    }

    private function bet(User $player, Fight $fight): void
    {
        Bet::create(['user_id' => $player->id, 'fight_id' => $fight->id, 'side' => 'meron', 'amount' => 100, 'status' => 'matched']);
    }

    public function test_another_fight_in_the_same_event_appears_only_if_theres_an_unsettled_bet_on_it(): void
    {
        $player = $this->player();
        $event = $this->event();
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB = Cockpit::create(['name' => 'Ring B', 'stream_url' => 'https://example.com/b.m3u8']);
        $ringC = Cockpit::create(['name' => 'Ring C', 'stream_url' => 'https://example.com/c.m3u8']);

        $current = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringA->id]);
        $betOn = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'closed', 'draw_enabled' => true, 'cockpit_id' => $ringB->id]);
        $noBet = Fight::create(['event_id' => $event->id, 'fight_number' => 3, 'status' => 'pending', 'draw_enabled' => true, 'cockpit_id' => $ringC->id]);

        $this->bet($player, $betOn);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $current));

        $response->assertOk();
        $pipFights = $response->viewData('pipFights');
        $this->assertCount(1, $pipFights);
        $this->assertSame($betOn->id, $pipFights->first()['fight_id']);
    }

    public function test_a_fight_on_the_same_cockpit_as_the_current_one_is_excluded_even_with_a_bet_on_it(): void
    {
        $player = $this->player();
        $event = $this->event();
        $ring = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);

        $current = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ring->id]);
        // Same cockpit re-used for a backlogged fight (shouldn't normally
        // happen given the cockpit-availability rule, but guard it anyway).
        $backlogged = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'closed', 'draw_enabled' => true, 'cockpit_id' => $ring->id]);
        $this->bet($player, $backlogged);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $current));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('pipFights'));
    }

    public function test_a_bet_riding_fight_with_no_cockpit_or_no_stream_is_excluded(): void
    {
        $player = $this->player();
        $event = $this->event();
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringNoStream = Cockpit::create(['name' => 'Ring B']); // no stream_url set

        $current = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringA->id]);
        $noStream = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'closed', 'draw_enabled' => true, 'cockpit_id' => $ringNoStream->id]);
        $noCockpit = Fight::create(['event_id' => $event->id, 'fight_number' => 3, 'status' => 'closed', 'draw_enabled' => true]); // no cockpit at all
        $this->bet($player, $noStream);
        $this->bet($player, $noCockpit);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $current));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('pipFights'));
    }

    public function test_status_endpoint_returns_the_same_pip_list_shape(): void
    {
        $player = $this->player();
        $event = $this->event();
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB = Cockpit::create(['name' => 'Ring B', 'stream_url' => 'https://example.com/b.m3u8']);

        $current = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringA->id]);
        $other = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'closed', 'draw_enabled' => true, 'cockpit_id' => $ringB->id]);
        $this->bet($player, $other);

        $response = $this->actingAs($player)->getJson(route('play.pool-fight.status', $current));

        $response->assertOk();
        $response->assertJson([
            'pip' => [
                ['fight_id' => $other->id, 'fight_number' => 2],
            ],
        ]);
    }

    public function test_pip_stream_url_requests_muted_autoplay_with_no_controls(): void
    {
        $player = $this->player();
        $event = $this->event();
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB = Cockpit::create(['name' => 'Ring B', 'stream_url' => 'https://example.com/b.m3u8']);

        $current = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringA->id]);
        $other = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'closed', 'draw_enabled' => true, 'cockpit_id' => $ringB->id]);
        $this->bet($player, $other);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $current));

        $response->assertOk();
        $pipFights = $response->viewData('pipFights');
        $this->assertStringContainsString('https://example.com/b.m3u8?', $pipFights->first()['stream_url']);
        $this->assertStringContainsString('autoplay=1', $pipFights->first()['stream_url']);
        $this->assertStringContainsString('mute=1', $pipFights->first()['stream_url']);
        $this->assertStringContainsString('controls=0', $pipFights->first()['stream_url']);
    }

    public function test_a_fight_in_another_event_appears_if_the_player_has_an_unsettled_bet_on_it(): void
    {
        $player = $this->player();
        $eventA = $this->event('Event A');
        $eventB = $this->event('Event B');
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB = Cockpit::create(['name' => 'Ring B', 'stream_url' => 'https://example.com/b.m3u8']);

        $fightA = Fight::create(['event_id' => $eventA->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringA->id]);
        $fightB = Fight::create(['event_id' => $eventB->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringB->id]);

        $this->bet($player, $fightA);

        // Player has switched to viewing Event B — their still-open bet on
        // Event A's fight should still show up in the PiP.
        $response = $this->actingAs($player)->get(route('play.pool-fight', $fightB));

        $response->assertOk();
        $pipFights = $response->viewData('pipFights');
        $this->assertCount(1, $pipFights);
        $this->assertSame($fightA->id, $pipFights->first()['fight_id']);
        $this->assertSame('Event A', $pipFights->first()['event_name']);
    }

    public function test_a_settled_bet_in_another_event_does_not_pin_that_fight_to_the_pip(): void
    {
        $player = $this->player();
        $eventA = $this->event('Event A');
        $eventB = $this->event('Event B');
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB = Cockpit::create(['name' => 'Ring B', 'stream_url' => 'https://example.com/b.m3u8']);

        $fightA = Fight::create(['event_id' => $eventA->id, 'fight_number' => 1, 'status' => 'declared', 'winner' => 'meron', 'draw_enabled' => true, 'cockpit_id' => $ringA->id]);
        $fightB = Fight::create(['event_id' => $eventB->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringB->id]);

        Bet::create(['user_id' => $player->id, 'fight_id' => $fightA->id, 'side' => 'meron', 'amount' => 100, 'status' => 'settled', 'payout' => 190]);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fightB));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('pipFights'));
    }

    public function test_the_same_event_name_is_null_for_a_tile_in_the_current_event(): void
    {
        $player = $this->player();
        $event = $this->event();
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB = Cockpit::create(['name' => 'Ring B', 'stream_url' => 'https://example.com/b.m3u8']);

        $current = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringA->id]);
        $other = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'closed', 'draw_enabled' => true, 'cockpit_id' => $ringB->id]);
        $this->bet($player, $other);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $current));

        $response->assertOk();
        $this->assertNull($response->viewData('pipFights')->first()['event_name']);
    }

    public function test_another_live_events_current_fight_does_not_appear_without_a_bet_on_it(): void
    {
        $player = $this->player();
        $eventA = $this->event('Event A');
        $eventB = $this->event('Event B');
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB = Cockpit::create(['name' => 'Ring B', 'stream_url' => 'https://example.com/b.m3u8']);

        $fightA = Fight::create(['event_id' => $eventA->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringA->id]);
        Fight::create(['event_id' => $eventB->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringB->id]);

        // No bet placed anywhere — Event B's current fight must NOT show up
        // just because it's live; only a bet riding on it would pin it here.
        $response = $this->actingAs($player)->get(route('play.pool-fight', $fightA));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('pipFights'));
    }

    public function test_only_the_bet_riding_fight_appears_not_the_other_events_newer_current_fight(): void
    {
        $player = $this->player();
        $eventA = $this->event('Event A');
        $eventB = $this->event('Event B');
        $ringA = Cockpit::create(['name' => 'Ring A', 'stream_url' => 'https://example.com/a.m3u8']);
        $ringB1 = Cockpit::create(['name' => 'Ring B1', 'stream_url' => 'https://example.com/b1.m3u8']);
        $ringB2 = Cockpit::create(['name' => 'Ring B2', 'stream_url' => 'https://example.com/b2.m3u8']);

        $fightA = Fight::create(['event_id' => $eventA->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringA->id]);

        // Event B: fight 1 is closed (awaiting declaration) and the player
        // has a bet riding on it; fight 2 is already open elsewhere with no
        // bet on it and must not appear just because it's Event B's current
        // fight.
        $fightB1 = Fight::create(['event_id' => $eventB->id, 'fight_number' => 1, 'status' => 'closed', 'draw_enabled' => true, 'cockpit_id' => $ringB1->id]);
        Fight::create(['event_id' => $eventB->id, 'fight_number' => 2, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $ringB2->id]);

        $this->bet($player, $fightB1);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $fightA));

        $response->assertOk();
        $pipFights = $response->viewData('pipFights');
        $this->assertCount(1, $pipFights);
        $this->assertSame($fightB1->id, $pipFights->first()['fight_id']);
    }

    public function test_pip_is_empty_when_no_other_fight_is_live(): void
    {
        $player = $this->player();
        $event = $this->event();
        $current = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        $response = $this->actingAs($player)->get(route('play.pool-fight', $current));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('pipFights'));
    }
}
