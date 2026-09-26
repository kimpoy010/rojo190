<?php

namespace Tests\Unit;

use App\Events\WalletBalanceUpdated;
use App\Models\Wallet;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Tests\TestCase;

class WalletBalanceUpdatedTest extends TestCase
{
    /**
     * Sent inline, same as BetPoolUpdated — regression guard for that
     * decision. This was queued in an earlier version specifically to keep
     * a bet-placement request from holding its PHP-FPM worker for the
     * Reverb round-trip; that tradeoff was reversed once low-latency
     * payout/balance reflection (<50ms) became a hard requirement, since
     * the `database` queue driver's polling alone could add up to a full
     * second of delay before a worker even picks the job up. If this
     * assertion ever needs to change back, that requirement needs
     * revisiting first, not just the code.
     */
    public function test_it_is_sent_inline_not_queued(): void
    {
        $this->assertInstanceOf(ShouldBroadcastNow::class, new WalletBalanceUpdated(new Wallet()));
    }

    public function test_it_broadcasts_on_a_private_per_user_channel(): void
    {
        $wallet = new Wallet(['user_id' => 42]);

        $channels = (new WalletBalanceUpdated($wallet))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-wallet.42', $channels[0]->name);
    }

    public function test_broadcast_as_name(): void
    {
        $this->assertSame('WalletBalanceUpdated', (new WalletBalanceUpdated(new Wallet()))->broadcastAs());
    }

    public function test_payload_includes_both_balances(): void
    {
        $wallet = new Wallet(['main_balance' => 1234.56, 'commission_balance' => 78.90]);

        $payload = (new WalletBalanceUpdated($wallet))->broadcastWith();

        $this->assertSame(1234.56, $payload['main_balance']);
        $this->assertSame(78.90, $payload['commission_balance']);
    }
}
