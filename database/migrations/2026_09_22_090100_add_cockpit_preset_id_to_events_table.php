<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('cockpit_preset_id')->nullable()->after('game_id')
                ->constrained()->nullOnDelete();
        });

        // Replaced by cockpit_preset_id — an event now points at a preset
        // (a curated group of cockpits) instead of a single hand-typed
        // URL, so the declarator/superadmin never has to know or retype
        // a raw stream URL when setting up an event.
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('stream_url');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('stream_url')->nullable();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cockpit_preset_id');
        });
    }
};
