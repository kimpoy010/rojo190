<?php

namespace Database\Seeders;

use App\Models\AgentLevel;
use Illuminate\Database\Seeder;

class AgentLevelSeeder extends Seeder
{
    /**
     * Default commission rate (%) a newly-created agent at this level is
     * seeded with for pool-sabong. Superadmin can adjust per-agent afterward.
     */
    private const DEFAULT_RATES = [
        1 => ['label' => 'Level 1 (Master Agent)', 'rate' => 2.00],
        2 => ['label' => 'Level 2 (Sub-Agent)', 'rate' => 1.50],
        3 => ['label' => 'Level 3 (Agent)', 'rate' => 1.00],
    ];

    public function run(): void
    {
        foreach (self::DEFAULT_RATES as $level => $data) {
            AgentLevel::firstOrCreate(
                ['level' => $level],
                ['label' => $data['label'], 'commission_rate' => $data['rate']]
            );
        }
    }
}
