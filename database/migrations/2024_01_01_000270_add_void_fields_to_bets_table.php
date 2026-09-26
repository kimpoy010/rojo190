<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A counter-bet ticket can be pulled by a teller with admin-approved PIN
 * sign-off (see App\Services\AdminPinService / BettingService::voidBet) —
 * the cash is handed back to the bettor and the stake stops counting
 * toward the fight's pool/payout odds (Bet::scopeInPool) and the writing
 * teller's shift cash-in (TellerShift::totals()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->enum('status', ['matched', 'settled', 'refunded', 'voided'])->default('matched')->change();

            $table->foreignId('voided_by_teller_id')->nullable()->after('redeemed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by_admin_id')->nullable()->after('voided_by_teller_id')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by_admin_id');
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by_teller_id');
            $table->dropConstrainedForeignId('voided_by_admin_id');
            $table->dropColumn('voided_at');

            $table->enum('status', ['matched', 'settled', 'refunded'])->default('matched')->change();
        });
    }
};
