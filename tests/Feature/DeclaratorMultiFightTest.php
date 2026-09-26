<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Services\BettingService;
use App\Services\FightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeclaratorMultiFightTest extends TestCase
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

    public function test_declarator_can_start_the_next_fight_while_the_previous_one_is_closed_awaiting_declaration(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        $fight1 = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'closed', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.events.fights.start-next', $event));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame('closed', $fight1->fresh()->status);
        $this->assertDatabaseHas('fights', ['event_id' => $event->id, 'fight_number' => 2, 'status' => 'pending']);
    }

    public function test_starting_next_fight_is_blocked_while_the_latest_fight_is_still_open(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.events.fights.start-next', $event));

        $response->assertStatus(422);
        $response->assertJson(['success' => false, 'message' => "Fight #1 hasn't closed betting yet."]);
        $this->assertDatabaseMissing('fights', ['event_id' => $event->id, 'fight_number' => 2]);
    }

    public function test_starting_next_fight_is_blocked_while_the_latest_fight_is_still_pending(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'pending', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.events.fights.start-next', $event));

        $response->assertStatus(422);
    }

    public function test_starting_next_fight_requires_a_live_event(): void
    {
        $declarator = $this->declarator();
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Upcoming Card', 'status' => 'upcoming', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->postJson(route('declarator.events.fights.start-next', $event));

        $response->assertStatus(422);
    }

    public function test_the_event_page_lists_every_undeclared_fight_including_older_backlog_ones(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'closed', 'draw_enabled' => true]);
        Fight::create(['event_id' => $event->id, 'fight_number' => 2, 'status' => 'open', 'draw_enabled' => true]);

        $response = $this->actingAs($declarator)->get(route('declarator.events.show', $event));

        $response->assertOk();
        $response->assertSee('Fight #1');
        $response->assertSee('Fight #2');
        $response->assertSee('Awaiting declaration');
    }

    public function test_an_older_backlogged_fight_can_still_be_declared_after_a_newer_fight_is_opened(): void
    {
        $declarator = $this->declarator();
        $event = $this->liveEvent();
        $fight1 = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $meronBettor = User::factory()->create();
        Wallet::create(['user_id' => $meronBettor->id, 'main_balance' => 1000]);
        $walaBettor = User::factory()->create();
        Wallet::create(['user_id' => $walaBettor->id, 'main_balance' => 1000]);
        app(BettingService::class)->placeBet($meronBettor, $fight1, 'meron', 100);
        app(BettingService::class)->placeBet($walaBettor, $fight1, 'wala', 100);
        $fight1->update(['status' => 'closed']);

        app(FightService::class)->startNextFight($event);
        $fight2 = $event->fights()->where('fight_number', 2)->first();
        $fight2->update(['status' => 'open']);

        $response = $this->actingAs($declarator)->postJson(route('declarator.fights.declare', $fight1), ['winner' => 'meron']);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertSame('declared', $fight1->fresh()->status);
        $this->assertSame('meron', $fight1->fresh()->winner);
        // The newer fight, still in progress, is untouched by declaring the backlog one.
        $this->assertSame('open', $fight2->fresh()->status);
    }
}
