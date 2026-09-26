<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('agent_level_id')->nullable()->after('agent_id')->constrained('agent_levels')->nullOnDelete();
            $table->string('referral_code')->nullable()->unique()->after('agent_level_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['agent_level_id']);
            $table->dropColumn(['agent_id', 'agent_level_id', 'referral_code']);
        });
    }
};
