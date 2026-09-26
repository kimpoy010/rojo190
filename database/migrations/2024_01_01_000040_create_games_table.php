<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('game_name')->unique();
            $table->string('display_name');
            $table->enum('game_status', ['active', 'inactive'])->default('active');
            // Percentage of the pool the house keeps as rake.
            $table->decimal('plasada', 5, 2)->default(5.00);
            // 'total_pool' rakes the combined pool; 'losing_side' rakes only the losing pool.
            $table->enum('plasada_mode', ['losing_side', 'total_pool'])->default('total_pool');
            // Fixed multiplier paid to draw bettors (house-funded, not pari-mutuel).
            $table->decimal('draw_multiplier', 8, 2)->default(8.00);
            // Hard cap on total amount that can be staked on draw for a single fight.
            $table->decimal('max_draw_bet', 10, 2)->default(100.00);
            // Display-only warning threshold shown to players (not enforced).
            $table->decimal('min_payout_threshold', 10, 2)->default(130.00);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
