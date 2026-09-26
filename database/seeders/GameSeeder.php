<?php

namespace Database\Seeders;

use App\Models\Game;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    public function run(): void
    {
        Game::firstOrCreate(
            ['game_name' => 'pool-sabong'],
            [
                'display_name' => 'Pool Sabong',
                'game_status' => 'active',
                'plasada' => 5.00,
                'plasada_mode' => 'total_pool',
                'draw_multiplier' => 8.00,
                'max_draw_bet' => 100.00,
                'min_payout_threshold' => 130.00,
            ]
        );

        Game::firstOrCreate(
            ['game_name' => 'combined-sabong'],
            [
                'game_type' => 'combined',
                'display_name' => 'Combined Sabong',
                'game_status' => 'active',
                'plasada' => 5.00,
                'plasada_mode' => 'total_pool',
                'odds_plasada' => 5.00,
                'draw_multiplier' => 8.00,
                'max_draw_bet' => 100.00,
                'min_payout_threshold' => 130.00,
            ]
        );
    }
}
