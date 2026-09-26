<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFID terminals are permanent hardware fixtures at a physical betting
 * window — they were never meant to need re-pointing at a new event every
 * time one starts. Drops the manual event binding; RfidTerminal::openFight()
 * now auto-resolves whichever event is currently live, the same pattern
 * already used for the teller ticket-writer (TicketController::activeEvent()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rfid_terminals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('rfid_terminals', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
