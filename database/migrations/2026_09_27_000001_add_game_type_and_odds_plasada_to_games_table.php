<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `game_type` distinguishes a game that needs its own player/declarator
 * routing (currently only 'combined', for CombinedSabong) from the default
 * pool-sabong behavior every existing Game row keeps (null). `odds_plasada`
 * is the rake rate for CombinedSabong's odds/fixed-bet subset, independent
 * of `plasada` (which the pool/totalizer subset keeps using unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('game_type')->nullable()->after('game_name');
            $table->decimal('odds_plasada', 5, 2)->nullable()->after('plasada_mode');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['game_type', 'odds_plasada']);
        });
    }
};
