<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            // Which teller shift a completed transaction's cash movement
            // counts against — set when a teller approves it. Null for
            // transactions that never completed (cancelled/expired) or that
            // predate this feature.
            $table->foreignId('teller_shift_id')->nullable()->after('teller_id')
                ->constrained('teller_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teller_shift_id');
        });
    }
};
