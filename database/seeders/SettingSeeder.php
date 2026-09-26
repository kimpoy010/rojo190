<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        // Matches the old config('pool.balancer_switch') default (false).
        Setting::firstOrCreate(
            ['setting_name' => 'balancer_switch'],
            ['value' => 'off']
        );
    }
}
