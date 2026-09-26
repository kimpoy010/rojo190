<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndUsersSeeder::class,
            DemoStaffAndPlayersSeeder::class,
            GameSeeder::class,
            OddsTierSeeder::class,
            AgentLevelSeeder::class,
            AgentDemoSeeder::class,
            SettingSeeder::class,
        ]);
    }
}
