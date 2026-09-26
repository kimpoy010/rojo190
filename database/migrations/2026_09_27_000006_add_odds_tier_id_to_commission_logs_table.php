<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_logs', function (Blueprint $table) {
            $table->foreignId('odds_tier_id')->nullable()->after('bet_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('commission_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('odds_tier_id');
        });
    }
};
