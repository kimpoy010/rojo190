<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\CashTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'player']);
        Role::firstOrCreate(['name' => 'teller']);

        $this->service = app(CashTransactionService::class);
    }

    private function player(float $balance = 1000): User
    {
        $player = User::factory()->create();
        $player->assignRole('player');
        Wallet::create(['user_id' => $player->id, 'main_balance' => $balance]);

        return $player;
    }

    private function teller(): User
    {
        $teller = User::factory()->create();
        $teller->assignRole('teller');
        Wallet::create(['user_id' => $teller->id]);

        return $teller;
    }

    public function test_deposit_credits_wallet_only_after_teller_approval(): void
    {
        $player = $this->player(1000);
        $teller = $this->teller();

        $tx = $this->service->createDeposit($player, 500);

        // Nothing credited yet — just a pending request.
        $this->assertEquals(1000, (float) $player->wallet->fresh()->main_balance);
        $this->assertSame('pending', $tx->status);

        $this->service->approve($tx, $teller);

        $this->assertEquals(1500, (float) $player->wallet->fresh()->main_balance);
        $this->assertSame('completed', $tx->fresh()->status);
        $this->assertSame($teller->id, $tx->fresh()->teller_id);
    }

    public function test_withdrawal_reserves_balance_immediately_but_debits_only_on_approval(): void
    {
        $player = $this->player(1000);
        $teller = $this->teller();

        $tx = $this->service->createWithdrawal($player);

        $this->assertEquals(1000, (float) $tx->amount);
        // main_balance untouched, but the full amount is reserved.
        $this->assertEquals(1000, (float) $player->wallet->fresh()->main_balance);
        $this->assertEquals(1000, (float) $player->wallet->fresh()->pending_withdrawal);
        $this->assertEquals(0, $player->wallet->fresh()->availableBalance());

        $this->service->approve($tx, $teller);

        $this->assertEquals(0, (float) $player->wallet->fresh()->main_balance);
        $this->assertEquals(0, (float) $player->wallet->fresh()->pending_withdrawal);
    }

    public function test_pending_withdrawal_blocks_betting_the_same_funds(): void
    {
        $player = $this->player(1000);
        $this->service->createWithdrawal($player);

        $this->expectException(\RuntimeException::class);
        app(\App\Services\WalletService::class)->debitBet($player->wallet->fresh(), 100, 'bet', null, 'test bet');
    }

    public function test_cannot_create_a_second_pending_request(): void
    {
        $player = $this->player(1000);
        $this->service->createDeposit($player, 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->createDeposit($player, 200);
    }

    public function test_cancelling_a_withdrawal_releases_the_reservation_without_debiting(): void
    {
        $player = $this->player(1000);
        $tx = $this->service->createWithdrawal($player);

        $this->service->cancel($tx);

        $this->assertEquals(1000, (float) $player->wallet->fresh()->main_balance);
        $this->assertEquals(0, (float) $player->wallet->fresh()->pending_withdrawal);
        $this->assertSame('cancelled', $tx->fresh()->status);
    }

    public function test_withdrawal_with_zero_available_balance_is_rejected(): void
    {
        $player = $this->player(0);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->createWithdrawal($player);
    }

    public function test_expired_request_cannot_be_approved(): void
    {
        $player = $this->player(1000);
        $teller = $this->teller();

        $tx = $this->service->createDeposit($player, 500);
        $tx->update(['expires_at' => now()->subMinute()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->approve($tx->fresh(), $teller);
    }

    public function test_expiring_a_stale_withdrawal_releases_its_reservation(): void
    {
        $player = $this->player(1000);
        $tx = $this->service->createWithdrawal($player);
        $tx->update(['expires_at' => now()->subMinute()]);

        $this->service->expire($tx->fresh());

        $this->assertEquals(0, (float) $player->wallet->fresh()->pending_withdrawal);
        $this->assertSame('expired', $tx->fresh()->status);
    }

    public function test_already_completed_request_cannot_be_approved_again(): void
    {
        $player = $this->player(1000);
        $teller = $this->teller();

        $tx = $this->service->createDeposit($player, 500);
        $this->service->approve($tx, $teller);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->approve($tx->fresh(), $teller);
    }
}
