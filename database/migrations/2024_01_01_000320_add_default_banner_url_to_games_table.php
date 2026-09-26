<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Fallback banner for any event under this game that hasn't had
            // its own thumbnail uploaded — see Event::displayBannerUrl().
            $table->string('default_banner_url')->nullable()->after('region');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('default_banner_url');
        });
    }
};
