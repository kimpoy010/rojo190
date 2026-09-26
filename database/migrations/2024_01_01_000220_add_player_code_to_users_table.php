<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Stable, opaque per-player identifier — not the username —
            // embedded in the player's "profile QR" so a teller can scan it
            // to identify the player instantly (e.g. when linking an RFID
            // card) instead of typing/searching a username. Generated
            // lazily on first use via User::profileCode(), so existing
            // rows don't need a backfill.
            $table->string('player_code', 20)->nullable()->unique()->after('rfid_uid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('player_code');
        });
    }
};
