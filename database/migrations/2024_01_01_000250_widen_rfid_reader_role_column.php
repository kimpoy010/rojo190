<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a 4th reader role, 'identify' — a reader at a teller's own counter
 * (as opposed to a self-service kiosk) used to identify a registered
 * player for a one-step deposit/withdrawal. Widening role from a DB-level
 * enum to a plain string avoids an enum-alteration dance across
 * SQLite/MySQL; valid values are still enforced at the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfid_readers', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rfid_readers', function (Blueprint $table) {
            $table->enum('role', ['meron', 'wala', 'topup'])->nullable()->change();
        });
    }
};
