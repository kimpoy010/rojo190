<?php

namespace Tests\Feature;

use App\Console\Commands\VerifyHashChains;
use App\Models\Bet;
use App\Models\CashTransaction;
use App\Models\Event;
use App\Models\Fight;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\BettingService;
use App\Services\CashTransactionService;
use App\Services\TellerShiftService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HashChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'teller']);
        Role::firstOrCreate(['name' => 'superadmin']);
    }

    private function player(): User
    {
        $player = User::factory()->create();
        $wallet = Wallet::create(['user_id' => $player->id, 'main_balance' => 1000]);
        $player->setRelation('wallet', $wallet);

        return $player;
    }

    private function fight(): Fight
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        Wallet::create(['user_id' => $admin->id, 'main_balance' => 1_000_000]);

        $game = Game::create([
            'game_name' => 'pool-sabong', 'display_name' => 'Pool Sabong', 'plasada' => 5.00,
            'plasada_mode' => 'total_pool', 'draw_multiplier' => 8.00, 'max_draw_bet' => 100.00,
            'min_payout_threshold' => 130.00,
        ]);
        $event = Event::create(['game_id' => $game->id, 'name' => 'Test Card', 'status' => 'live', 'draw_enabled' => true]);

        return Fight::create(['event_id' => $event->id, 'fight_number' => 1, 'status' => 'open', 'draw_enabled' => true]);
    }

    public function test_wallet_transactions_chain_sequentially_per_wallet(): void
    {
        $player = $this->player();
        $walletService = app(WalletService::class);

        $t1 = $walletService->credit($player->wallet, 100, 'test', null, 'first');
        $t2 = $walletService->credit($player->wallet, 50, 'test', null, 'second');

        $this->assertNotNull($t1->hash);
        $this->assertNull($t1->previous_hash);
        $this->assertSame($t1->hash, $t2->previous_hash);
        $this->assertNotSame($t1->hash, $t2->hash);
    }

    public function test_different_wallets_chain_independently(): void
    {
        $playerA = $this->player();
        $playerB = $this->player();
        $walletService = app(WalletService::class);

        $walletService->credit($playerA->wallet, 100, 'test', null, 'a1');
        $bFirst = $walletService->credit($playerB->wallet, 100, 'test', null, 'b1');

        // Player B's first transaction starts its own chain even though
        // player A's wallet already has history — chains are per-scope.
        $this->assertNull($bFirst->previous_hash);
    }

    public function test_placing_a_bet_chains_per_player(): void
    {
        $player = $this->player();
        $fight = $this->fight();

        $bet1 = app(BettingService::class)->placeBet($player, $fight, 'meron', 50);
        $bet2 = app(BettingService::class)->placeBet($player, $fight, 'wala', 25);

        $this->assertNotNull($bet1->hash);
        $this->assertNull($bet1->previous_hash);
        $this->assertSame($bet1->hash, $bet2->previous_hash);

        // The linked WalletTransaction for each bet also got its own hash.
        $this->assertNotNull(WalletTransaction::where('reference_type', 'bet')->first()->hash);
    }

    public function test_a_bets_hash_survives_its_status_changing_later(): void
    {
        $player = $this->player();
        $fight = $this->fight();

        $bet = app(BettingService::class)->placeBet($player, $fight, 'meron', 50);
        $originalHash = $bet->hash;

        // Settlement mutates status/payout on this same row — the hash
        // must not change, since it only ever covers the creation fact.
        $bet->update(['status' => 'settled', 'payout' => 95]);

        $this->assertSame($originalHash, $bet->fresh()->hash);
    }

    public function test_counter_bets_chain_per_teller_shift(): void
    {
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);
        $shift = app(TellerShiftService::class)->startShift($teller, 1000);
        $fight = $this->fight();

        $bettingService = app(BettingService::class);
        $bet1 = $bettingService->placeCounterBet($teller, $shift, $fight, 'meron', 50);
        $bet2 = $bettingService->placeCounterBet($teller, $shift, $fight, 'wala', 25);

        $this->assertNull($bet1->user_id);
        $this->assertNull($bet1->previous_hash);
        $this->assertSame($bet1->hash, $bet2->previous_hash);
    }

    public function test_cash_transaction_hash_survives_approval(): void
    {
        $player = $this->player();
        $transaction = app(CashTransactionService::class)->createDeposit($player, 500);
        $originalHash = $transaction->hash;
        $this->assertNotNull($originalHash);

        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        app(CashTransactionService::class)->approve($transaction, $teller);

        // status/teller_id/completed_at all changed — the hash (which only
        // covers the original request terms) must not have.
        $this->assertSame($originalHash, $transaction->fresh()->hash);

        // Also confirms expires_at (a datetime cast) round-trips through
        // the hash consistently between creation and later verification.
        $this->artisan(VerifyHashChains::class)->assertExitCode(0);
    }

    public function test_verify_command_passes_on_untampered_data(): void
    {
        $player = $this->player();
        app(WalletService::class)->credit($player->wallet, 100, 'test', null, 'ok');

        $this->artisan(VerifyHashChains::class)->assertExitCode(0);
    }

    public function test_verify_command_detects_a_row_edited_directly(): void
    {
        $player = $this->player();
        $transaction = app(WalletService::class)->credit($player->wallet, 100, 'test', null, 'ok');

        // Bypass the model entirely — a raw update, like an attacker with
        // direct DB access, never touches hashChainFields()/creating().
        WalletTransaction::where('id', $transaction->id)->update(['amount' => 99999]);

        $this->artisan(VerifyHashChains::class)->assertExitCode(1);
    }

    public function test_verify_command_detects_a_deleted_row(): void
    {
        $player = $this->player();
        $walletService = app(WalletService::class);

        $walletService->credit($player->wallet, 100, 'test', null, 'first');
        $second = $walletService->credit($player->wallet, 50, 'test', null, 'second');
        $walletService->credit($player->wallet, 25, 'test', null, 'third');

        WalletTransaction::where('id', $second->id)->delete();

        $this->artisan(VerifyHashChains::class)->assertExitCode(1);
    }

    public function test_backfill_hashes_legacy_rows_and_verify_then_passes(): void
    {
        $player = $this->player();

        // Simulate data written before this feature existed: create rows
        // the same way the app itself creates them, then strip the hash
        // columns straight in the DB to mimic a pre-migration row.
        $walletService = app(WalletService::class);
        $t1 = $walletService->credit($player->wallet, 100, 'legacy', null, 'legacy 1');
        $t2 = $walletService->credit($player->wallet, 50, 'legacy', null, 'legacy 2');
        WalletTransaction::whereIn('id', [$t1->id, $t2->id])->update(['hash' => null, 'previous_hash' => null]);
        \App\Models\ChainHead::where('chain', 'wallet_transactions')->delete();

        // Unhashed legacy data alone must not itself count as tampering.
        $this->artisan(VerifyHashChains::class)->assertExitCode(0);

        $this->artisan('ledger:backfill')->assertExitCode(0);

        $t1->refresh();
        $t2->refresh();
        $this->assertNotNull($t1->hash);
        $this->assertNull($t1->previous_hash);
        $this->assertSame($t1->hash, $t2->previous_hash);

        $this->artisan(VerifyHashChains::class)->assertExitCode(0);
    }

    public function test_backfill_is_idempotent(): void
    {
        $player = $this->player();
        $walletService = app(WalletService::class);
        $t1 = $walletService->credit($player->wallet, 100, 'legacy', null, 'legacy 1');
        WalletTransaction::where('id', $t1->id)->update(['hash' => null, 'previous_hash' => null]);
        \App\Models\ChainHead::where('chain', 'wallet_transactions')->delete();

        $this->artisan('ledger:backfill')->assertExitCode(0);
        $hashAfterFirstRun = $t1->fresh()->hash;

        // A live row created normally after the backfill...
        $t2 = $walletService->credit($player->wallet, 25, 'test', null, 'after backfill');

        // ...then re-running the backfill must not touch either row again.
        $this->artisan('ledger:backfill')->assertExitCode(0);

        $this->assertSame($hashAfterFirstRun, $t1->fresh()->hash);
        $this->assertSame($t2->hash, $t2->fresh()->hash);
        $this->artisan(VerifyHashChains::class)->assertExitCode(0);
    }
}
