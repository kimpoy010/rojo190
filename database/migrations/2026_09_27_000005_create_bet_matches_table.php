<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per matched slice between an opposing meron/wala odds bet pair
 * on a CombinedSabong fight — see CombinedBettingService::matchBet(). A
 * single Bet can appear in many rows (matched piecemeal against several
 * opponents), which is why settlement sums over these rather than over
 * Bet.matched_amount alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bet_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meron_bet_id')->constrained('bets')->cascadeOnDelete();
            $table->foreignId('wala_bet_id')->constrained('bets')->cascadeOnDelete();
            $table->foreignId('odds_tier_id')->constrained()->cascadeOnDelete();
            $table->decimal('matched_amount', 10, 2);
            $table->timestamps();

            $table->index('meron_bet_id');
            $table->index('wala_bet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_matches');
    }
};
