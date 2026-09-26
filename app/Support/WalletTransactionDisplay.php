<?php

namespace App\Support;

/**
 * Display lookups (short label, action verb, icon) per wallet transaction
 * reference_type — shared by the player wallet page's row list, regardless
 * of whether it's the initial full-page load or a filtered/paginated AJAX
 * fetch (see wallet-transaction-rows.blade.php). A plain PHP class rather
 * than a Blade partial: @include only passes data INTO the included view,
 * never back out, so @php-assigned variables there are invisible to the
 * including view — this used to live in _wallet-tx-maps.blade.php, whose
 * @include left referenceLabels/actionLabels/txIcons silently undefined in
 * every row (every icon fell back to the same generic "!" glyph).
 */
class WalletTransactionDisplay
{
    /**
     * @return array{referenceLabels: array<string, string>, actionLabels: array<string, string>, txIcons: array<string, array{path: string, color: string}>}
     */
    public static function maps(): array
    {
        return [
            'referenceLabels' => [
                'bet' => __('Bet placed'),
                'payout' => __('Bet payout'),
                'refund' => __('Bet refund'),
                'draw_payout' => __('Draw payout funding'),
                'deposit' => __('Cash deposit'),
                'withdrawal' => __('Cash withdrawal'),
                // Still a deposit/withdrawal from the player's own
                // perspective — just initiated by an admin instead of the
                // player's own Cash In/Out request, same folding the
                // Deposits/Withdrawals tabs already do (see
                // Player\WalletController::TAB_REFERENCE_TYPES).
                'admin_topup' => __('Cash deposit'),
                'admin_withdraw' => __('Cash withdrawal'),
                'reversal' => __('Payout reversal'),
                'commission_transfer' => __('Commission transfer'),
            ],

            // Short verb phrase for the small "as of" line above each row,
            // e.g. "Bet placed on" / "Deposit approved on".
            'actionLabels' => [
                'bet' => __('Bet placed'),
                'payout' => __('Payout received'),
                'refund' => __('Refund issued'),
                'draw_payout' => __('Draw payout'),
                'deposit' => __('Deposit approved'),
                'withdrawal' => __('Withdrawal approved'),
                'admin_topup' => __('Deposit approved'),
                'admin_withdraw' => __('Withdrawal approved'),
                'reversal' => __('Payout reversed'),
                'commission_transfer' => __('Commission transferred'),
            ],

            // Icon + tint per reference_type, independent of credit/debit —
            // a wallet activity list reads faster by "what kind of thing
            // happened" than by sign alone once payout/refund/reversal all
            // share a color.
            'txIcons' => [
                'deposit' => ['path' => 'M12 5v14M5 12l7 7 7-7', 'color' => '#34d399'],
                'withdrawal' => ['path' => 'M12 19V5M5 12l7-7 7 7', 'color' => '#f87171'],
                'admin_topup' => ['path' => 'M12 5v14M5 12l7 7 7-7', 'color' => '#34d399'],
                'admin_withdraw' => ['path' => 'M12 19V5M5 12l7-7 7 7', 'color' => '#f87171'],
                'bet' => ['path' => 'M12 12h.01M8 12h.01M16 12h.01', 'color' => '#f87171'],
                'payout' => ['path' => 'M12 2l2.5 6.5L21 9l-5 4.5L17.5 21 12 17l-5.5 4L8 13.5 3 9l6.5-.5z', 'color' => '#fbbf24'],
                'draw_payout' => ['path' => 'M12 2l2.5 6.5L21 9l-5 4.5L17.5 21 12 17l-5.5 4L8 13.5 3 9l6.5-.5z', 'color' => '#fbbf24'],
                'refund' => ['path' => 'M3 12a9 9 0 106-8.49M3 3v6h6', 'color' => '#5eead4'],
                'reversal' => ['path' => 'M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z', 'color' => '#f59e0b'],
                'commission_transfer' => ['path' => 'M17 1l4 4-4 4M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4M21 13v2a4 4 0 01-4 4H3', 'color' => '#93c5fd'],
            ],
        ];
    }
}
