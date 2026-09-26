<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A meron:wala matching ratio (e.g. "10-9") a fixed-odds bet can be placed
 * at — see App\Services\CombinedBettingService. Global, superadmin-managed;
 * which of these a given event actually offers is decided by the
 * event_odds_tiers pivot (see next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odds_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->decimal('meron_ratio', 5, 2);
            $table->decimal('wala_ratio', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odds_tiers');
    }
};
