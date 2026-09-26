<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a 'last_call' status between 'open' and 'closed' — a "closing very
 * soon" warning stage (see Fight::BETTABLE_STATUSES; betting itself is
 * unaffected). Widening status from a DB-level enum to a plain string
 * avoids an enum-alteration dance across SQLite/MySQL, same reasoning as
 * rfid_readers.role earlier; valid values stay enforced at the application
 * layer (Fight::IN_PLAY_STATUSES / FightService's own transition guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fights', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('fights', function (Blueprint $table) {
            $table->enum('status', ['pending', 'open', 'closed', 'declared', 'cancelled'])->default('pending')->change();
        });
    }
};
