<?php

namespace Database\Seeders;

use App\Models\OddsTier;
use Illuminate\Database\Seeder;

class OddsTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            ['label' => '10-10', 'meron_ratio' => 10, 'wala_ratio' => 10],
            ['label' => '10-9', 'meron_ratio' => 10, 'wala_ratio' => 9],
            ['label' => '9-10', 'meron_ratio' => 9, 'wala_ratio' => 10],
            ['label' => '10-8', 'meron_ratio' => 10, 'wala_ratio' => 8],
            ['label' => '8-10', 'meron_ratio' => 8, 'wala_ratio' => 10],
            ['label' => '10-7', 'meron_ratio' => 10, 'wala_ratio' => 7],
            ['label' => '7-10', 'meron_ratio' => 7, 'wala_ratio' => 10],
            ['label' => '10-6', 'meron_ratio' => 10, 'wala_ratio' => 6],
            ['label' => '6-10', 'meron_ratio' => 6, 'wala_ratio' => 10],
        ];

        foreach ($tiers as $tier) {
            OddsTier::firstOrCreate(['label' => $tier['label']], [
                'meron_ratio' => $tier['meron_ratio'],
                'wala_ratio' => $tier['wala_ratio'],
                'is_active' => true,
            ]);
        }
    }
}
