<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'teller']);
    }

    private function player(string $username = 'juan'): User
    {
        $player = User::factory()->create(['username' => $username, 'password' => Hash::make('password')]);
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 500]);

        return $player;
    }

    private function login(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('login'), ['login' => $user->username, 'password' => 'password']);
    }

    private function liveEventWithFight(string $name, string $date, string $fightStatus = 'open'): Fight
    {
        $game = Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00, 'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);

        $event = Event::create(['game_id' => $game->id, 'name' => $name, 'status' => 'live', 'draw_enabled' => true, 'date' => $date]);

        return Fight::create(['event_id' => $event->id, 'fight_number' => 3, 'status' => $fightStatus, 'draw_enabled' => true]);
    }

    public function test_a_player_logging_in_lands_on_the_live_events_current_fight(): void
    {
        $fight = $this->liveEventWithFight('Card A', '2026-01-01 10:00:00');
        $player = $this->player();

        $this->login($player)->assertRedirect(route('play.pool-fight', $fight));
    }

    public function test_with_multiple_live_events_the_earliest_one_by_date_wins(): void
    {
        $this->liveEventWithFight('Card B (later)', '2026-01-02 10:00:00');
        $firstFight = $this->liveEventWithFight('Card A (earlier)', '2026-01-01 10:00:00');
        $player = $this->player();

        $this->login($player)->assertRedirect(route('play.pool-fight', $firstFight));
    }

    public function test_a_player_logging_in_with_no_live_event_lands_on_the_events_list(): void
    {
        $player = $this->player();

        $this->login($player)->assertRedirect(route('play.index'));
    }

    public function test_a_player_logging_in_when_the_live_event_has_no_fight_yet_lands_on_the_events_list(): void
    {
        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        Event::create(['game_id' => $game->id, 'name' => 'Card A', 'status' => 'live', 'draw_enabled' => true]);
        $player = $this->player();

        $this->login($player)->assertRedirect(route('play.index'));
    }

    public function test_an_intended_url_still_takes_priority_over_the_live_fight_redirect(): void
    {
        $this->liveEventWithFight('Card A', '2026-01-01 10:00:00');
        $player = $this->player();

        // Hitting a protected page while logged out stores it as "intended".
        $this->get(route('play.wallet.index'));

        $this->login($player)->assertRedirect(route('play.wallet.index'));
    }

    public function test_a_non_player_still_lands_on_their_own_home_route(): void
    {
        $teller = User::factory()->create(['password' => Hash::make('password')]);
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        $this->login($teller)->assertRedirect(route('teller.dashboard'));
    }
}
