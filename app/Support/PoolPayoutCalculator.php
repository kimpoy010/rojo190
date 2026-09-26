<?php

namespace App\Support;

use App\Models\Setting;

class PoolPayoutCalculator
{
    /**
     * Calculate meron/wala payout ratios from raw pool totals.
     *
     * When the 'balancer_switch' setting (App\Models\Setting, editable from
     * Superadmin\SettingsController) is 'on', the winning side's payout is
     * capped so meronPayout + walaPayout never exceeds the mode's balancer
     * total — this prevents extreme payouts when one side is heavily
     * favoured.
     *
     * $mode controls where plasada (the house rake) is taken from:
     *   'total_pool' (default) — rake the entire combined pool before splitting:
     *       net available = (meron + wala) x (1 - plasada%); ratio = net available / own pool
     *   'losing_side' — rake only the opponent's pool:
     *       if meron wins → rake = wala x plasada%; net to meron = meron + wala x (1 - plasada%)
     *       if wala wins  → rake = meron x plasada%; net to wala  = wala  + meron x (1 - plasada%)
     *
     * Returns ratios (e.g. 1.90 means the bettor gets 1.90x their stake back)
     * plus percent versions of the same two numbers for display
     * (meron_pct/wala_pct, to 2 decimal places) — see the comment above
     * their computation below for why, with the balancer on, wala's isn't
     * simply round($ratio * 100, 2) applied independently.
     *
     * @return array{meron: float, wala: float, meron_pct: float, wala_pct: float}
     */
    public static function calculate(float $meron, float $wala, float $plasada, string $mode = 'total_pool'): array
    {
        $balancerOn = Setting::get('balancer_switch', 'off') === 'on';
        $balancerTotal = null;

        if ($mode === 'total_pool') {
            $netAvailable = ($meron + $wala) * (1 - $plasada / 100);
            $meronPayout = $meron > 0 ? $netAvailable / $meron : 0.0;
            $walaPayout = $wala > 0 ? $netAvailable / $wala : 0.0;

            if ($balancerOn && $meron > 0 && $wala > 0) {
                // When pools are equal, sum of both ratios equals this total.
                $balancerTotal = 4 * (1 - $plasada / 100);

                // Sequential ifs (not elseif) — matches the reference
                // calculateOdds() this was ported from: the second check
                // re-reads whichever ratio the first if just changed, so in
                // an extreme enough pool it can adjust both sides, not just
                // the favourite.
                if ($meronPayout > $walaPayout) {
                    $meronPayout = $balancerTotal - min($meronPayout, $walaPayout);
                }
                if ($walaPayout > $meronPayout) {
                    $walaPayout = $balancerTotal - min($meronPayout, $walaPayout);
                }
            }
        } else {
            $factor = 1 - $plasada / 100;
            $meronNet = $meron + $wala * $factor;
            $walaNet = $wala + $meron * $factor;
            $meronPayout = $meron > 0 ? $meronNet / $meron : 0.0;
            $walaPayout = $wala > 0 ? $walaNet / $wala : 0.0;

            if ($balancerOn && $meron > 0 && $wala > 0) {
                $balancerTotal = 4 - 2 * ($plasada / 100);

                if ($meronPayout > $walaPayout) {
                    $meronPayout = $balancerTotal - $walaPayout;
                } elseif ($walaPayout > $meronPayout) {
                    $walaPayout = $balancerTotal - $meronPayout;
                }
            }
        }

        $meronPayout = round(max(0.0, $meronPayout), 4);
        $walaPayout = round(max(0.0, $walaPayout), 4);

        $meronPct = round($meronPayout * 100, 2);
        $walaPct = round($walaPayout * 100, 2);

        // The balancer guarantees the two *precise* ratios sum to exactly
        // $balancerTotal (e.g. 1.615 + 2.185 = 3.8) — but rounding each side
        // to 2 decimals independently doesn't always preserve that at the
        // last decimal place. Deriving wala's displayed percent from the
        // target minus meron's keeps the sum exact; wala's own rounding
        // error this way is provably no larger than an independent round()
        // would have given it anyway, since the two ratios' errors are each
        // other's mirror image around the same fixed total.
        if ($balancerTotal !== null) {
            $walaPct = round(round($balancerTotal * 100, 2) - $meronPct, 2);
        }

        return [
            'meron' => $meronPayout,
            'wala' => $walaPayout,
            'meron_pct' => max(0.0, $meronPct),
            'wala_pct' => max(0.0, $walaPct),
        ];
    }
}
