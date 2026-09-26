<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fights', function (Blueprint $table) {
            // Null until the declarator assigns one — required before a
            // pending fight can be opened for betting (see FightController::open()).
            $table->foreignId('cockpit_id')->nullable()->after('event_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cockpit_id');
        });
    }
};
