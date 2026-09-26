<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Drives the betting labels/colors shown across the app for
            // this game's events — see App\Support\GameTheme. Configured
            // on the same settings page as plasada, so deploying the same
            // codebase to a different market is a one-field switch.
            $table->string('region', 20)->default('philippines')->after('game_status');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }
};
