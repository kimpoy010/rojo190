<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['wallet_transactions', 'cash_transactions', 'bets'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('hash', 64)->nullable()->after('id');
                $blueprint->string('previous_hash', 64)->nullable()->after('hash');
            });
        }
    }

    public function down(): void
    {
        foreach (['wallet_transactions', 'cash_transactions', 'bets'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['hash', 'previous_hash']);
            });
        }
    }
};
