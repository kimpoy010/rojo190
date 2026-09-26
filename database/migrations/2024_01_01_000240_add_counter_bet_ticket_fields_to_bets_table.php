<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports over-the-counter "bet writer" tickets: a teller takes a cash bet
 * from a walk-up bettor who has no account/wallet at all. The bet still
 * shares the same fight pool as regular app/kiosk bets (so pool totals and
 * payout percentages stay correct) — it's just not tied to a User, and its
 * cash flow (stake collected, winnings paid) is tracked against the
 * teller's shift instead of a wallet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            // A counter/ticket bet has no bettor account.
            $table->foreignId('user_id')->nullable()->change();

            // Who wrote the ticket, and which shift the stake counts as
            // cash-in for. Both null for ordinary app/kiosk bets.
            $table->foreignId('placed_by_teller_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('placed_teller_shift_id')->nullable()->after('placed_by_teller_id')->constrained('teller_shifts')->nullOnDelete();

            // The QR-embedded reference a bettor brings back to collect a
            // win. Null for ordinary bets, which never need one — payout
            // there is an automatic wallet credit at settlement time.
            $table->string('ticket_code', 20)->nullable()->unique()->after('placed_teller_shift_id');

            // Who paid out a winning ticket's cash, and which shift that
            // payout counts as cash-out for — may be a different teller
            // and a different shift than the one that wrote the ticket,
            // since a bettor can return anytime after the fight settles.
            $table->foreignId('redeemed_by_teller_id')->nullable()->after('ticket_code')->constrained('users')->nullOnDelete();
            $table->foreignId('redeemed_teller_shift_id')->nullable()->after('redeemed_by_teller_id')->constrained('teller_shifts')->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable()->after('redeemed_teller_shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('placed_by_teller_id');
            $table->dropConstrainedForeignId('placed_teller_shift_id');
            $table->dropColumn('ticket_code');
            $table->dropConstrainedForeignId('redeemed_by_teller_id');
            $table->dropConstrainedForeignId('redeemed_teller_shift_id');
            $table->dropColumn('redeemed_at');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
