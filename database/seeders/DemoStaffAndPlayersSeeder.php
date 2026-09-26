<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class DemoStaffAndPlayersSeeder extends Seeder
{
    /**
     * Ten player accounts and ten teller accounts for local demos and
     * manual testing — RolesAndUsersSeeder already gives you one of each
     * (player@example.com / teller@example.com); this fills out a bigger
     * roster so flows like teller-to-teller lookups or player search have
     * more than a single record to work against.
     */
    public function run(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $player = User::firstOrCreate(
                ['email' => "player{$i}@example.com"],
                [
                    'name' => "Demo Player {$i}",
                    'username' => "player{$i}",
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]
            );
            if (! $player->hasRole('player')) {
                $player->assignRole('player');
            }
            Wallet::firstOrCreate(['user_id' => $player->id], ['main_balance' => 1000]);
        }

        for ($i = 1; $i <= 10; $i++) {
            $teller = User::firstOrCreate(
                ['email' => "teller{$i}@example.com"],
                [
                    'name' => "Demo Teller {$i}",
                    'username' => "teller{$i}",
                    'password' => bcrypt('password'),
                    'email_verified_at' => now(),
                ]
            );
            if (! $teller->hasRole('teller')) {
                $teller->assignRole('teller');
            }
            Wallet::firstOrCreate(['user_id' => $teller->id]);
        }
    }
}
