<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which odds tiers a given (CombinedSabong) event offers its players, and
 * in what order — superadmin-assigned per event, same pattern as
 * cockpit_presets. An event with no rows here falls back to every globally
 * active OddsTier (see CombinedBettingService::placeBet()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_odds_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('odds_tier_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'odds_tier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_odds_tiers');
    }
};
