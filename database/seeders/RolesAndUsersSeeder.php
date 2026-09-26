<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesAndUsersSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['player', 'declarator', 'superadmin', 'agent', 'teller'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $superadmin = User::firstOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Super Admin',
                'username' => 'superadmin',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'referral_code' => 'ROOTADMIN',
                // Demo-only approval PIN, used to sign off a teller's void
                // request in person — change it via Approval PIN in a real
                // deployment.
                'pin' => '1234',
            ]
        );
        if (! $superadmin->hasRole('superadmin')) {
            $superadmin->assignRole('superadmin');
        }
        if (! $superadmin->hasPin()) {
            $superadmin->update(['pin' => '1234']);
        }
        Wallet::firstOrCreate(['user_id' => $superadmin->id], ['main_balance' => 1_000_000]);

        $declarator = User::firstOrCreate(
            ['email' => 'declarator@example.com'],
            [
                'name' => 'Fight Declarator',
                'username' => 'declarator',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        if (! $declarator->hasRole('declarator')) {
            $declarator->assignRole('declarator');
        }
        Wallet::firstOrCreate(['user_id' => $declarator->id]);

        $teller = User::firstOrCreate(
            ['email' => 'teller@example.com'],
            [
                'name' => 'Cashier Teller',
                'username' => 'teller',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        if (! $teller->hasRole('teller')) {
            $teller->assignRole('teller');
        }
        Wallet::firstOrCreate(['user_id' => $teller->id]);

        $player = User::firstOrCreate(
            ['email' => 'player@example.com'],
            [
                'name' => 'Demo Player',
                'username' => 'player',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );
        if (! $player->hasRole('player')) {
            $player->assignRole('player');
        }
        Wallet::firstOrCreate(['user_id' => $player->id], ['main_balance' => 1000]);
    }
}
