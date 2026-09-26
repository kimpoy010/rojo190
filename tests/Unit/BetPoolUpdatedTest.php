<?php

namespace Tests\Unit;

use App\Events\BetPoolUpdated;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Tests\TestCase;

class BetPoolUpdatedTest extends TestCase
{
    public function test_it_implements_should_broadcast(): void
    {
        $this->assertInstanceOf(ShouldBroadcast::class, new BetPoolUpdated(1, 100, 50));
    }

    /**
     * Must stay synchronous, deliberately — this is the public, per-fight
     * pool total every bettor watches live. Queuing it risks it ticking
     * (or a page reload showing a different figure) after the fight has
     * already shown CLOSED, which reads as untrustworthy regardless of
     * whether the number is technically correct. WalletBalanceUpdated is
     * the one broadcast that was judged safe to queue instead — see
     * WalletBalanceUpdatedTest.
     */
    public function test_it_stays_synchronous_not_queued(): void
    {
        $this->assertInstanceOf(ShouldBroadcastNow::class, new BetPoolUpdated(1, 100, 50));
    }

    public function test_it_broadcasts_on_a_single_fight_channel(): void
    {
        $event = new BetPoolUpdated(42, 100, 50);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertSame('fight.42', $channels[0]->name);
    }

    public function test_broadcast_as_name(): void
    {
        $this->assertSame('BetPoolUpdated', (new BetPoolUpdated(1, 0, 0))->broadcastAs());
    }

    public function test_payload_includes_pool_totals_and_defaults_draw_pool_to_zero(): void
    {
        $payload = (new BetPoolUpdated(1, 100.0, 50.0))->broadcastWith();

        $this->assertSame(100.0, $payload['meron_pool']);
        $this->assertSame(50.0, $payload['wala_pool']);
        $this->assertSame(0.00, $payload['draw_pool']);
    }
}
