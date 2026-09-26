<?php

namespace Database\Seeders;

use App\Models\AgentCommissionRate;
use App\Models\AgentLevel;
use App\Models\Game;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AgentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $game = Game::where('game_name', 'pool-sabong')->first();
        $l1 = AgentLevel::where('level', 1)->first();

        if (! $game || ! $l1) {
            return;
        }

        $agent = User::firstOrCreate(
            ['email' => 'agent@example.com'],
            [
                'name' => 'Demo Agent',
                'username' => 'agent',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'agent_level_id' => $l1->id,
                'referral_code' => Str::upper(Str::random(8)),
            ]
        );
        if (! $agent->hasRole('agent')) {
            $agent->assignRole('agent');
        }
        Wallet::firstOrCreate(['user_id' => $agent->id]);

        AgentCommissionRate::firstOrCreate(
            ['agent_id' => $agent->id, 'game_id' => $game->id],
            ['commission_rate' => $l1->commission_rate]
        );

        // Recruit the demo player under the demo agent so the commission chain
        // has something to demonstrate out of the box.
        $player = User::where('email', 'player@example.com')->first();
        if ($player && ! $player->agent_id) {
            $player->update(['agent_id' => $agent->id]);
        }
    }
}
