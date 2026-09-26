<?php

namespace App\Services;

use App\Models\RfidTerminal;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks the short-lived "armed" amount a self-service kiosk holds between
 * a player picking an amount on screen and physically tapping their card on
 * a reader. The physical readers have no display of their own and no way
 * to receive an amount, so the browser arms the terminal first and the tap
 * (relayed by the serial bridge) consumes whatever is currently armed.
 *
 * Bet and top-up amounts are armed independently since they use separate
 * readers and can be staged at the same time.
 */
class RfidKioskService
{
    private const TTL_SECONDS = 60;

    public function arm(RfidTerminal $terminal, string $context, float $amount): void
    {
        Cache::put($this->key($terminal, $context), $amount, self::TTL_SECONDS);
    }

    /**
     * Returns the armed amount, or null if nothing was armed (or it expired
     * before the tap arrived).
     */
    public function armedAmount(RfidTerminal $terminal, string $context): ?float
    {
        return Cache::get($this->key($terminal, $context));
    }

    public function clear(RfidTerminal $terminal, string $context): void
    {
        Cache::forget($this->key($terminal, $context));
    }

    private function key(RfidTerminal $terminal, string $context): string
    {
        return "rfid_terminal:{$terminal->id}:armed:{$context}";
    }
}
