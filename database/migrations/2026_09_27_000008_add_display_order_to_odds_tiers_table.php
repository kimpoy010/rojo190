<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Superadmin-controlled display order for the global odds tier list —
 * drives both the Odds Tiers admin page and the fallback tier list a
 * CombinedSabong event with no custom event_odds_tiers assignment uses
 * (see CombinedBetController::tiersForEvent()). Backfilled from current
 * id order so existing tiers keep their present ordering until reordered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odds_tiers', function (Blueprint $table) {
            $table->unsignedSmallInteger('display_order')->default(0)->after('is_active');
        });

        DB::table('odds_tiers')->orderBy('id')->pluck('id')->each(function ($id, $index) {
            DB::table('odds_tiers')->where('id', $id)->update(['display_order' => $index]);
        });
    }

    public function down(): void
    {
        Schema::table('odds_tiers', function (Blueprint $table) {
            $table->dropColumn('display_order');
        });
    }
};
