<?php

namespace Tests\Unit;

use App\Support\PoolPayoutCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoolPayoutCalculatorTest extends TestCase
{
    // The balancer-switch test below now reads App\Models\Setting, which
    // needs the settings table — everything else in this file stays pure
    // math with no DB, but the trait applies per-class, not per-test.
    use RefreshDatabase;


    public function test_losing_side_mode_rakes_only_the_opponents_pool(): void
    {
        // meron 100, wala 6, plasada 5% → meronNet = 100 + 6*0.95 = 105.7 → ratio 1.057
        $result = PoolPayoutCalculator::calculate(100, 6, 5.00, 'losing_side');

        $this->assertEqualsWithDelta(1.057, $result['meron'], 0.0001);
    }

    public function test_total_pool_mode_rakes_the_combined_pool(): void
    {
        // (100+100) * 0.95 = 190; ratio = 190/100 = 1.9 for both sides
        $result = PoolPayoutCalculator::calculate(100, 100, 5.00, 'total_pool');

        $this->assertEqualsWithDelta(1.9, $result['meron'], 0.0001);
        $this->assertEqualsWithDelta(1.9, $result['wala'], 0.0001);
    }

    public function test_total_pool_is_the_default_mode_when_none_is_given(): void
    {
        // meron 100, wala 6, plasada 5% → total_pool: (100+6)*0.95 = 100.7 → ratio 1.007,
        // which differs from losing_side's 1.057 for the same inputs (see the first test) —
        // confirms the implicit default really is total_pool, not losing_side.
        $result = PoolPayoutCalculator::calculate(100, 6, 5.00);

        $this->assertEqualsWithDelta(1.007, $result['meron'], 0.0001);
    }

    public function test_empty_pool_returns_zero_payout(): void
    {
        $result = PoolPayoutCalculator::calculate(0, 0, 5.00);

        $this->assertSame(0.0, $result['meron']);
        $this->assertSame(0.0, $result['wala']);
    }

    public function test_balancer_switch_caps_the_combined_payout(): void
    {
        \App\Models\Setting::set('balancer_switch', 'on');

        // Heavily lopsided: meron 900, wala 100, plasada 5%.
        // meronNet = 900 + 100*0.95 = 995 → ratio ≈ 1.1056
        // walaNet  = 100 + 900*0.95 = 955 → ratio = 9.55
        // balancerTotal = 4 - 2*0.05 = 3.9, so wala should be capped to 3.9 - 1.1056 ≈ 2.7944
        $result = PoolPayoutCalculator::calculate(900, 100, 5.00, 'losing_side');

        $this->assertEqualsWithDelta(3.9, $result['meron'] + $result['wala'], 0.0001);
        $this->assertLessThan(9.55, $result['wala']);
    }

    /**
     * meron 50, wala 35, plasada 5% (total_pool) is the exact case that
     * exposed this: the precise ratios are 1.615 and 2.185 — summing to
     * exactly 3.8, as the balancer promises. At 2 decimal places these
     * round independently to the same values anyway (161.5% and 218.5%),
     * but wala_pct is still derived from the target rather than
     * independently rounded so the two displayed numbers a player sees are
     * guaranteed to always add up to what was promised, even when a less
     * tidy pool would otherwise drift the sum off by a cent.
     */
    public function test_balancer_percents_always_sum_to_the_balancer_total(): void
    {
        \App\Models\Setting::set('balancer_switch', 'on');

        $result = PoolPayoutCalculator::calculate(50, 35, 5.00, 'total_pool');

        $this->assertEqualsWithDelta(1.615, $result['meron'], 0.0001);
        $this->assertEqualsWithDelta(2.185, $result['wala'], 0.0001);
        $this->assertSame(161.5, $result['meron_pct']);
        $this->assertSame(218.5, $result['wala_pct']);
        $this->assertSame(380.0, $result['meron_pct'] + $result['wala_pct']);
    }

    public function test_percents_round_independently_when_balancer_is_off(): void
    {
        \App\Models\Setting::set('balancer_switch', 'off');

        $result = PoolPayoutCalculator::calculate(50, 35, 5.00, 'total_pool');

        $this->assertSame(161.5, $result['meron_pct']);
        $this->assertSame(230.71, $result['wala_pct']);
    }
}
