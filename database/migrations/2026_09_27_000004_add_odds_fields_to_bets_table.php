<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports CombinedSabong's fixed-odds (matched) bets alongside the
 * existing pool bets, on the same `bets` table:
 *
 * - `odds_tier_id` set        -> a fixed-odds bet, matched at that tier.
 * - `odds_tier_id` null       -> a pool bet (every existing bet, unchanged).
 *
 * `matched_amount`/`unmatched_amount` track order-matching progress for an
 * odds bet (see CombinedBettingService::matchBet()) — a pool bet is always
 * fully matched at placement, so both simply equal amount/0. The status
 * enum gains 'pending' (no match yet), 'partially_matched', and
 * 'cancelled' (unmatched portion pulled back before the fight closed) —
 * additive only, every existing status value keeps its exact meaning for
 * pool-sabong bets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->foreignId('odds_tier_id')->nullable()->after('side')->constrained()->nullOnDelete();
            $table->decimal('matched_amount', 10, 2)->nullable()->after('amount');
            $table->decimal('unmatched_amount', 10, 2)->nullable()->after('matched_amount');
        });

        Schema::table('bets', function (Blueprint $table) {
            $table->enum('status', ['pending', 'partially_matched', 'matched', 'settled', 'refunded', 'voided', 'cancelled'])
                ->default('matched')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('odds_tier_id');
            $table->dropColumn(['matched_amount', 'unmatched_amount']);
        });

        Schema::table('bets', function (Blueprint $table) {
            $table->enum('status', ['matched', 'settled', 'refunded', 'voided'])->default('matched')->change();
        });
    }
};
