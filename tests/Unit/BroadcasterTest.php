<?php

namespace Tests\Unit;

use App\Events\BetPoolUpdated;
use App\Support\Broadcaster;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression coverage for a real bug: when the Reverb server is unreachable,
 * broadcast()-ing an event throws (surfacing from PendingBroadcast's
 * __destruct, since ShouldBroadcastNow dispatches synchronously). Call sites
 * across the app fire broadcasts *after* the underlying state change (a bet
 * placed, a fight declared, a wallet credited/debited) has already
 * committed — so that exception must never propagate back to the caller as
 * if the whole action failed. Broadcaster::send() is the chokepoint that
 * guarantees this.
 */
class BroadcasterTest extends TestCase
{
    public function test_send_swallows_a_broadcast_failure_instead_of_letting_it_propagate(): void
    {
        $this->app->instance('events', $this->makeThrowingDispatcher());

        // Would throw and fail the request if Broadcaster::send() didn't
        // catch it — this call must complete without raising anything.
        Broadcaster::send(new BetPoolUpdated(1, 100.0, 50.0));

        $this->assertTrue(true);
    }

    public function test_send_logs_a_warning_on_failure(): void
    {
        $this->app->instance('events', $this->makeThrowingDispatcher());

        Log::shouldReceive('warning')
            ->once()
            ->with('broadcast.failed', \Mockery::on(function (array $context) {
                return $context['event'] === BetPoolUpdated::class
                    && str_contains($context['error'], 'Reverb unreachable');
            }));

        Broadcaster::send(new BetPoolUpdated(1, 100.0, 50.0));
    }

    private function makeThrowingDispatcher(): Dispatcher
    {
        return new class implements Dispatcher
        {
            public function listen($events, $listener = null) {}

            public function hasListeners($eventName)
            {
                return false;
            }

            public function subscribe($subscriber) {}

            public function until($event, $payload = []) {}

            public function dispatch($event, $payload = [], $halt = false)
            {
                // Only fail the broadcast dispatch under test — the same
                // 'events' singleton also carries Laravel's own internal
                // events (e.g. Log fires MessageLogged through it), which
                // must keep working or the test's own assertions break.
                if ($event instanceof BetPoolUpdated) {
                    throw new \RuntimeException('Reverb unreachable: connection refused');
                }

                return null;
            }

            public function push($event, $payload = []) {}

            public function flush($event) {}

            public function forget($event) {}

            public function forgetPushed() {}
        };
    }
}
