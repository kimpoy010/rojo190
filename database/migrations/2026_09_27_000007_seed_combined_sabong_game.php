<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the CombinedSabong Game row the same way GameSeeder does for
 * pool-sabong — as a migration (not just the seeder) so it exists on any
 * environment that only ever runs `migrate`, not `migrate --seed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('games')->where('game_name', 'combined-sabong')->exists();

        if (! $exists) {
            DB::table('games')->insert([
                'game_name' => 'combined-sabong',
                'game_type' => 'combined',
                'display_name' => 'Combined Sabong',
                'game_status' => 'active',
                'plasada' => 5.00,
                'plasada_mode' => 'total_pool',
                'odds_plasada' => 5.00,
                'draw_multiplier' => 8.00,
                'max_draw_bet' => 100.00,
                'min_payout_threshold' => 130.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('games')->where('game_name', 'combined-sabong')->delete();
    }
};
