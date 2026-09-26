<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

class Broadcaster
{
    /**
     * Broadcast a realtime event without letting a broadcast failure (e.g.
     * the Reverb server being unreachable) fail the request that triggered
     * it. By the time this runs, the underlying state change (a bet placed,
     * a fight declared, a wallet credited) has already happened — losing
     * the live update just means clients fall back to their existing
     * polling loop, which is a degraded experience, not a reason to report
     * a successful action as an error.
     */
    public static function send(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('broadcast.failed', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
