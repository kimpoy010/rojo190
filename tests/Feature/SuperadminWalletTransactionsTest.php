<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\BettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperadminWalletTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'superadmin']);
        Role::firstOrCreate(['name' => 'player']);

        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id]);

        return $admin;
    }

    private function player(): User
    {
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        return $player;
    }

    private function game(): Game
    {
        return Game::firstOrCreate(['game_name' => 'pool-sabong'], [
            'display_name' => 'Pool Sabong', 'plasada' => 5.00, 'plasada_mode' => 'total_pool',
            'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
    }

    public function test_a_superadmin_can_view_a_players_transaction_history(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        WalletTransaction::create([
            'wallet_id' => $player->wallet->id, 'type' => 'credit', 'amount' => 500, 'balance_after' => 1500,
            'reference_type' => 'deposit', 'description' => 'Cash deposit via teller Juan',
        ]);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.transactions', $player));

        $response->assertOk();
        $response->assertSee($player->displayName());
        $response->assertSee('Cash deposit via teller Juan');
    }

    public function test_the_bets_tab_only_shows_bet_linked_transactions(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $event = Event::create(['game_id' => $this->game()->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        app(BettingService::class)->placeBet($player, $fight, 'meron', 50);

        WalletTransaction::create([
            'wallet_id' => $player->wallet->id, 'type' => 'credit', 'amount' => 500, 'balance_after' => 1450,
            'reference_type' => 'deposit', 'description' => 'Cash deposit via teller Juan',
        ]);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.transactions', [$player, 'tab' => 'bets']));

        $response->assertOk();
        $response->assertSee('Bet on meron for Fight #1');
        $response->assertDontSee('Cash deposit via teller Juan');
    }

    public function test_a_bet_transaction_in_the_bets_tab_shows_its_event_name(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $event = Event::create(['game_id' => $this->game()->id, 'name' => 'Grand Derby Card', 'status' => 'live', 'draw_enabled' => true]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 7, 'status' => 'open', 'draw_enabled' => true]);
        app(BettingService::class)->placeBet($player, $fight, 'wala', 50);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.transactions', [$player, 'tab' => 'bets']));

        $response->assertOk();
        $response->assertSee('Grand Derby Card');
    }

    public function test_the_deposits_tab_includes_admin_topups(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        WalletTransaction::create([
            'wallet_id' => $player->wallet->id, 'type' => 'credit', 'amount' => 200, 'balance_after' => 1200,
            'reference_type' => 'admin_topup', 'description' => 'Manual top-up by superadmin',
        ]);
        WalletTransaction::create([
            'wallet_id' => $player->wallet->id, 'type' => 'debit', 'amount' => 100, 'balance_after' => 1100,
            'reference_type' => 'withdrawal', 'description' => 'Cash withdrawal via teller Juan',
        ]);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.transactions', [$player, 'tab' => 'deposits']));

        $response->assertOk();
        $response->assertSee('Manual top-up by superadmin');
        $response->assertDontSee('Cash withdrawal via teller Juan');
    }

    public function test_the_withdrawals_tab_includes_admin_withdrawals(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        WalletTransaction::create([
            'wallet_id' => $player->wallet->id, 'type' => 'debit', 'amount' => 200, 'balance_after' => 800,
            'reference_type' => 'admin_withdraw', 'description' => 'Manual withdrawal by superadmin',
        ]);
        WalletTransaction::create([
            'wallet_id' => $player->wallet->id, 'type' => 'credit', 'amount' => 100, 'balance_after' => 900,
            'reference_type' => 'deposit', 'description' => 'Cash deposit via teller Juan',
        ]);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.transactions', [$player, 'tab' => 'withdrawals']));

        $response->assertOk();
        $response->assertSee('Manual withdrawal by superadmin');
        $response->assertDontSee('Cash deposit via teller Juan');
    }

    public function test_the_date_range_filters_transactions(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $old = WalletTransaction::create([
            'wallet_id' => $player->wallet->id, 'type' => 'credit', 'amount' => 100, 'balance_after' => 1100,
            'reference_type' => 'deposit', 'description' => 'Old deposit',
        ]);
        $old->created_at = now()->subDays(10);
        $old->saveQuietly();

        WalletTransaction::create([
            'wallet_id' => $player->wallet->id, 'type' => 'credit', 'amount' => 100, 'balance_after' => 1200,
            'reference_type' => 'deposit', 'description' => 'Recent deposit',
        ]);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.transactions', [
            $player, 'date_from' => now()->subDays(2)->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('Recent deposit');
        $response->assertDontSee('Old deposit');
    }

    public function test_the_event_filter_only_applies_within_the_bets_tab(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $eventA = Event::create(['game_id' => $this->game()->id, 'name' => 'Event A', 'status' => 'live', 'draw_enabled' => true]);
        $eventB = Event::create(['game_id' => $this->game()->id, 'name' => 'Event B', 'status' => 'live', 'draw_enabled' => true]);
        $fightA = Fight::create(['event_id' => $eventA->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $fightB = Fight::create(['event_id' => $eventB->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        app(BettingService::class)->placeBet($player, $fightA, 'meron', 50);
        app(BettingService::class)->placeBet($player, $fightB, 'wala', 50);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.transactions', [
            $player, 'tab' => 'bets', 'event_id' => $eventA->id,
        ]));

        $response->assertOk();
        $response->assertSee('Fight #1');
        $transactions = $response->viewData('transactions');
        $this->assertCount(1, $transactions->items());
    }

    public function test_pagination_is_twenty_per_page(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        for ($i = 0; $i < 25; $i++) {
            WalletTransaction::create([
                'wallet_id' => $player->wallet->id, 'type' => 'credit', 'amount' => 10, 'balance_after' => 1000,
                'reference_type' => 'deposit', 'description' => "Tx {$i}",
            ]);
        }

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.transactions', $player));

        $response->assertOk();
        $transactions = $response->viewData('transactions');
        $this->assertSame(20, $transactions->perPage());
        $this->assertCount(20, $transactions->items());
        $this->assertTrue($transactions->hasMorePages());
    }

    public function test_a_non_player_target_is_rejected(): void
    {
        $admin = $this->admin();
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('superadmin');
        Wallet::create(['user_id' => $otherAdmin->id]);

        $response = $this->actingAs($admin)->get(route('superadmin.wallets.transactions', $otherAdmin));

        $response->assertStatus(422);
    }

    public function test_a_non_superadmin_cannot_view_the_page(): void
    {
        Role::firstOrCreate(['name' => 'teller']);
        Role::firstOrCreate(['name' => 'player']);
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);
        $player = $this->player();

        $response = $this->actingAs($teller)->get(route('superadmin.wallets.transactions', $player));

        $response->assertForbidden();
    }
}
