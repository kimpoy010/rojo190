<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data-only migration: pool-sabong's default plasada_mode changed from
     * 'losing_side' to 'total_pool'. Anyone who already migrated+seeded
     * before this change would otherwise be stuck on the old default
     * forever, since the seeder only ever creates the row, never updates
     * it. Only touches rows still sitting on the old default — an operator
     * who deliberately chose 'losing_side' after the fact is left alone.
     */
    public function up(): void
    {
        DB::table('games')->where('plasada_mode', 'losing_side')->update(['plasada_mode' => 'total_pool']);
    }

    public function down(): void
    {
        // Irreversible by design — we can't distinguish "still on the old
        // default" from "deliberately chosen" after the fact.
    }
};
