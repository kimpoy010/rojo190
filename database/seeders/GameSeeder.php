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
    }
}
