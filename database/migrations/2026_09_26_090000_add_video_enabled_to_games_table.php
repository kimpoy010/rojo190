<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Lets a superadmin turn off the live video feed and the
            // floating "other fights" PiP panel on the player betting page
            // entirely (e.g. no reliable stream provider for this venue
            // yet) without touching cockpit assignment — declarators still
            // pick a cockpit per fight as normal, it just isn't shown to
            // players while this is off.
            $table->boolean('video_enabled')->default(true)->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('video_enabled');
        });
    }
};
