<?php

namespace Tests\Feature;

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

class PlayerWalletControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_view_their_wallet_transaction_history(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 850]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 1000,
            'balance_after' => 1000,
            'reference_type' => 'deposit',
            'description' => 'Cash deposit via teller Juan',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount' => 150,
            'balance_after' => 850,
            'reference_type' => 'bet',
            'description' => 'Bet on meron for Fight #1',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index'));

        $response->assertOk();
        $response->assertSee('Cash deposit via teller Juan');
        $response->assertSee('Bet on meron for Fight #1');
        $response->assertSee('850.00');
    }

    public function test_wallet_history_paginates_fifteen_per_page(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        for ($i = 0; $i < 20; $i++) {
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'amount' => 10,
                'balance_after' => 1000,
                'reference_type' => 'deposit',
                'description' => "Tx {$i}",
            ]);
        }

        $response = $this->actingAs($player)->get(route('play.wallet.index'));

        $response->assertOk();
        $transactions = $response->viewData('transactions');
        $this->assertSame(15, $transactions->perPage());
        $this->assertCount(15, $transactions->items());
        $this->assertTrue($transactions->hasMorePages());
    }

    public function test_a_bet_transaction_carries_its_events_name(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Grand Derby Card', 'status' => 'live', 'draw_enabled' => true]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 15, 'status' => 'open', 'draw_enabled' => true]);

        app(BettingService::class)->placeBet($player, $fight, 'meron', 20);

        $tx = WalletTransaction::where('reference_type', 'bet')->firstOrFail();
        $this->assertNotNull($tx->reference_id, 'the bet transaction should now carry the bet id');

        $response = $this->actingAs($player)->get(route('play.wallet.index'));

        $response->assertOk();
        $response->assertSee('Bet on meron for Fight #15');
        $response->assertSee('Grand Derby Card');
        $response->assertViewHas('eventNamesByBetId', function ($map) use ($tx) {
            return ($map[$tx->reference_id] ?? null) === 'Grand Derby Card';
        });
    }

    public function test_a_deposits_reference_id_never_borrows_an_unrelated_bets_event_name(): void
    {
        // A CashTransaction's id can numerically collide with an unrelated
        // Bet's id — the lookup must be gated by reference_type, not just
        // "is reference_id present in the map".
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Some Card', 'status' => 'live', 'draw_enabled' => true]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $bet = app(BettingService::class)->placeBet($player, $fight, 'meron', 20);

        // A deposit whose reference_id happens to equal that same bet's id.
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'amount' => 500,
            'balance_after' => 1480,
            'reference_type' => 'deposit',
            'reference_id' => $bet->id,
            'description' => 'Cash deposit via teller Juan',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<button[^>]*data-reference-type="deposit"[^>]*data-event-name=""[^>]*>/s',
            $html,
            "the deposit row's data-event-name must be empty, not borrowed from the bet it numerically collides with"
        );
    }

    public function test_the_type_filter_only_returns_matching_transactions(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 850]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 1000, 'balance_after' => 1000,
            'reference_type' => 'deposit', 'description' => 'Cash deposit via teller Juan',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'debit', 'amount' => 150, 'balance_after' => 850,
            'reference_type' => 'bet', 'description' => 'Bet on meron for Fight #1',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index', ['type' => 'bet']));

        $response->assertOk();
        $response->assertSee('Bet on meron for Fight #1');
        $response->assertDontSee('Cash deposit via teller Juan');
        $transactions = $response->viewData('transactions');
        $this->assertCount(1, $transactions->items());
    }

    public function test_an_ajax_request_returns_only_the_rows_partial(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 1000, 'balance_after' => 1000,
            'reference_type' => 'deposit', 'description' => 'Cash deposit via teller Juan',
        ]);

        $response = $this->actingAs($player)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('play.wallet.index'));

        $response->assertOk();
        $response->assertSee('Cash deposit via teller Juan');
        $response->assertDontSee('<h1');
        $response->assertDontSee('WALLET BALANCE');
    }

    public function test_an_unknown_filter_type_is_rejected(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        $response = $this->actingAs($player)->get(route('play.wallet.index', ['type' => 'admin_topup']));

        $response->assertSessionHasErrors('type');
    }

    public function test_the_bets_tab_only_shows_bet_linked_transactions(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        app(BettingService::class)->placeBet($player, $fight, 'meron', 20);

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 500, 'balance_after' => 1480,
            'reference_type' => 'deposit', 'description' => 'Cash deposit via teller Juan',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index', ['tab' => 'bets']));

        $response->assertOk();
        $response->assertSee('Bet on meron for Fight #1');
        $response->assertDontSee('Cash deposit via teller Juan');
    }

    public function test_the_bets_tab_can_be_filtered_by_event(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00, 'min_payout_threshold' => 130.00,
        ]);
        $eventA = Event::create(['game_id' => $game->id, 'name' => 'Event A', 'status' => 'live', 'draw_enabled' => true]);
        $eventB = Event::create(['game_id' => $game->id, 'name' => 'Event B', 'status' => 'live', 'draw_enabled' => true]);
        $fightA = Fight::create(['event_id' => $eventA->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
        $fightB = Fight::create(['event_id' => $eventB->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);

        app(BettingService::class)->placeBet($player, $fightA, 'meron', 20);
        app(BettingService::class)->placeBet($player, $fightB, 'wala', 30);

        $response = $this->actingAs($player)->get(route('play.wallet.index', ['tab' => 'bets', 'event_id' => $eventA->id]));

        $response->assertOk();
        $transactions = $response->viewData('transactions');
        $this->assertCount(1, $transactions->items());
        $response->assertSee('Event A');
    }

    public function test_the_deposits_tab_can_be_filtered_by_amount(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 500, 'balance_after' => 1500,
            'reference_type' => 'deposit', 'description' => 'Five hundred deposit',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 200, 'balance_after' => 1700,
            'reference_type' => 'deposit', 'description' => 'Two hundred deposit',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index', ['tab' => 'deposits', 'amount' => 500]));

        $response->assertOk();
        $response->assertSee('Five hundred deposit');
        $response->assertDontSee('Two hundred deposit');
    }

    public function test_the_deposits_tab_includes_admin_topups(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 200, 'balance_after' => 1200,
            'reference_type' => 'admin_topup', 'description' => 'Manual top-up by superadmin',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'debit', 'amount' => 100, 'balance_after' => 1100,
            'reference_type' => 'withdrawal', 'description' => 'Cash withdrawal via teller Juan',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index', ['tab' => 'deposits']));

        $response->assertOk();
        $response->assertSee('Manual top-up by superadmin');
        $response->assertDontSee('Cash withdrawal via teller Juan');
    }

    public function test_the_withdrawals_tab_can_be_filtered_by_date_range(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        $old = WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'debit', 'amount' => 50, 'balance_after' => 950,
            'reference_type' => 'withdrawal', 'description' => 'Old withdrawal',
        ]);
        $old->created_at = now()->subDays(10);
        $old->saveQuietly();

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'debit', 'amount' => 75, 'balance_after' => 875,
            'reference_type' => 'withdrawal', 'description' => 'Recent withdrawal',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index', [
            'tab' => 'withdrawals', 'date_from' => now()->subDays(2)->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('Recent withdrawal');
        $response->assertDontSee('Old withdrawal');
    }

    public function test_the_type_filter_only_applies_on_the_all_tab(): void
    {
        Role::firstOrCreate(['name' => 'player']);
        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 500, 'balance_after' => 1500,
            'reference_type' => 'deposit', 'description' => 'A deposit',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'debit', 'amount' => 100, 'balance_after' => 1400,
            'reference_type' => 'withdrawal', 'description' => 'A withdrawal',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index', ['tab' => 'all', 'type' => 'deposit']));

        $response->assertOk();
        $response->assertSee('A deposit');
        $response->assertDontSee('A withdrawal');
    }

    public function test_wallet_page_is_only_accessible_to_players(): void
    {
        Role::firstOrCreate(['name' => 'teller']);

        $teller = User::factory()->create();
        $teller->assignRole('teller');

        $response = $this->actingAs($teller)->get(route('play.wallet.index'));

        $response->assertForbidden();
    }

    /**
     * The row's visible one-liner is a short "<Side> - Fight #<n>" derived
     * from the bet itself (colored to match that side's theme) rather than
     * the raw stored description, which can run too long for one line —
     * the original description still shows in the transaction detail
     * modal via the row's data-title attribute (see the other bet tests
     * above, which assert on that instead).
     */
    public function test_a_bet_transactions_row_shows_a_short_colored_side_and_fight_label(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Grand Derby Card', 'status' => 'live', 'draw_enabled' => true]);
        $fight = Fight::create(['event_id' => $event->id, 'fight_number' => 15, 'status' => 'open', 'draw_enabled' => true]);

        app(BettingService::class)->placeBet($player, $fight, 'meron', 20);

        $response = $this->actingAs($player)->get(route('play.wallet.index'));

        $response->assertOk();
        $response->assertSee('Meron - Fight #15');
        $response->assertSee('text-red-300', false);
    }

    /**
     * A transaction whose reference_id doesn't resolve to any Bet (legacy
     * data written before reference_id was consistently populated, or a
     * bet that's since been deleted) still gets a short row label — a
     * generic "Bet placed" salvaging whatever fight number it can find in
     * the stored description — rather than the full original sentence.
     * The original description is never lost: it's still on the row via
     * data-title, for the detail modal.
     */
    public function test_a_bet_transaction_with_no_resolvable_bet_gets_a_short_fallback_label(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'type' => 'debit',
            'amount' => 150,
            'balance_after' => 850,
            'reference_type' => 'bet',
            'reference_id' => 999999,
            'description' => 'Bet on meron for Fight #1',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index'));

        $response->assertOk();
        // The full original description still lives in data-title, for
        // the detail modal — that's what this really asserts on.
        $response->assertSee('Bet on meron for Fight #1');
        $response->assertSee('Bet placed - Fight #1');
    }

    /**
     * Deposits and withdrawals (player-initiated or an admin's manual
     * adjustment) get the same short-label treatment as bets — the
     * teller/admin's name in the stored description doesn't need to be on
     * the row itself, and both categories are tinted to match their
     * dedicated deposit/withdrawal icon color.
     */
    public function test_deposit_and_withdrawal_rows_show_a_short_label(): void
    {
        Role::firstOrCreate(['name' => 'player']);

        $player = User::factory()->create();
        $player->assignRole('player');
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 500, 'balance_after' => 1500,
            'reference_type' => 'deposit', 'description' => 'Cash deposit via teller Juan Dela Cruz',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'debit', 'amount' => 200, 'balance_after' => 1300,
            'reference_type' => 'withdrawal', 'description' => 'Cash withdrawal via teller Juan Dela Cruz',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'credit', 'amount' => 900, 'balance_after' => 2200,
            'reference_type' => 'admin_topup', 'description' => 'Manual top-up by superadmin',
        ]);
        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'type' => 'debit', 'amount' => 300, 'balance_after' => 1900,
            'reference_type' => 'admin_withdraw', 'description' => 'Manual withdrawal by superadmin',
        ]);

        $response = $this->actingAs($player)->get(route('play.wallet.index'));

        $response->assertOk();
        // The row's visible span (color-tinted by the deposit/withdrawal
        // icon color, not just any "Cash deposit" text elsewhere on the
        // page, like the Type filter's own dropdown option).
        $response->assertSee('style="color: #34d399">Cash deposit</span>', false);
        $response->assertSee('style="color: #f87171">Cash withdrawal</span>', false);
        // The full original sentences are still preserved for the modal.
        $response->assertSee('Cash deposit via teller Juan Dela Cruz');
        $response->assertSee('Cash withdrawal via teller Juan Dela Cruz');
        $response->assertSee('Manual top-up by superadmin');
        $response->assertSee('Manual withdrawal by superadmin');
    }
}
