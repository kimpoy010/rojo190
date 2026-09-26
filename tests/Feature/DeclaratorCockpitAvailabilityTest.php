<?php

namespace Tests\Feature;

use App\Models\Cockpit;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Services\FightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeclaratorCockpitAvailabilityTest extends TestCase
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

    public function test_opening_bets_is_blocked_on_a_cockpit_already_holding_another_pending_fight(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        $cockpit = Cockpit::create(['name' => 'Ring 1']);

        Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'pending', 'draw_enabled' => true, 'cockpit_id' => $cockpit->id]);
        $fight2 = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.open', $fight2), ['cockpit_id' => $cockpit->id]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'message' => 'That cockpit is already in use by another fight in this event.']);
        $this->assertSame('pending', $fight2->fresh()->status);
    }

    public function test_a_different_event_can_use_the_same_cockpit_at_the_same_time(): void
    {
        $declarator = $this->declarator();
        $eventA = $this->liveEvent();
        $cockpit = Cockpit::create(['name' => 'Ring 1']);

        Fight::create(['event_id' => $eventA->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $cockpit->id]);

        $eventB = Event::create(['game_id' => $eventA->game_id, 'name' => 'Other Card', 'status' => 'live', 'draw_enabled' => true]);
        $fightB = Fight::create(['event_id' => $eventB->id, 'fight_number' => 1, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.open', $fightB), ['cockpit_id' => $cockpit->id]);

        $response->assertOk();
        $this->assertSame('open', $fightB->fresh()->status);
        $this->assertSame($cockpit->id, $fightB->fresh()->cockpit_id);
    }

    public function test_opening_bets_on_a_cockpit_already_held_by_itself_is_allowed(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        $cockpit = Cockpit::create(['name' => 'Ring 1']);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'pending', 'draw_enabled' => true, 'cockpit_id' => $cockpit->id]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.open', $fight), ['cockpit_id' => $cockpit->id]);

        $response->assertOk();
        $this->assertSame('open', $fight->fresh()->status);
    }

    public function test_a_cockpit_freed_up_by_a_declared_fight_becomes_available_again(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        $cockpit = Cockpit::create(['name' => 'Ring 1']);

        $fight1 = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'closed', 'draw_enabled' => true, 'cockpit_id' => $cockpit->id]);
        app(FightService::class)->declareWinner($fight1, 'meron');

        // declareWinner auto-creates fight #2 — no need to create it ourselves.
        $fight2 = $event->fights()->where('fight_number', 2)->firstOrFail();

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.open', $fight2), ['cockpit_id' => $cockpit->id]);

        $response->assertOk();
        $this->assertSame('open', $fight2->fresh()->status);
        $this->assertSame($cockpit->id, $fight2->fresh()->cockpit_id);
    }

    public function test_reassigning_a_busy_cockpit_via_updatecockpit_is_also_blocked(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        $cockpit = Cockpit::create(['name' => 'Ring 1']);

        $fight1 = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $cockpit->id]);
        $fight2 = Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.cockpit', $fight2), ['cockpit_id' => $cockpit->id]);

        $response->assertStatus(422);
        $this->assertNull($fight2->fresh()->cockpit_id);
        $this->assertSame($cockpit->id, $fight1->fresh()->cockpit_id);
    }

    public function test_the_open_bets_form_disables_the_radio_for_a_cockpit_already_in_use(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        $busyCockpit = Cockpit::create(['name' => 'Ring 1']);
        $freeCockpit = Cockpit::create(['name' => 'Ring 2']);

        Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $busyCockpit->id]);
        Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->get(route('declarator.events.show', $event));

        $response->assertOk();
        // The busy cockpit's own radio carries disabled; the free one doesn't.
        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/value="'.$busyCockpit->id.'"[^>]*disabled/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/value="'.$freeCockpit->id.'"[^>]*disabled/',
            $html
        );
    }

    public function test_the_open_bets_form_does_not_disable_a_cockpit_busy_only_in_another_event(): void
    {
        $declarator = $this->declarator();
        $eventA = $this->liveEvent();
        $cockpit = Cockpit::create(['name' => 'Ring 1']);

        $eventB = Event::create(['game_id' => $eventA->game_id, 'name' => 'Other Card', 'status' => 'live', 'draw_enabled' => true]);
        Fight::create(['event_id' => $eventB->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true, 'cockpit_id' => $cockpit->id]);

        Fight::create(['event_id' => $eventA->id, 'fight_number' => 1, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->get(route('declarator.events.show', $eventA));

        $response->assertOk();
        $this->assertDoesNotMatchRegularExpression(
            '/value="'.$cockpit->id.'"[^>]*disabled/',
            $response->getContent()
        );
    }
}
