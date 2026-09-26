<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Amount reserved by a pending withdrawal request — held out of the
            // "available to bet/withdraw again" balance until the teller
            // approves (debits it for real) or the request is cancelled/expires
            // (releases it). main_balance itself is untouched until approval.
            $table->decimal('pending_withdrawal', 15, 2)->default(0)->after('commission_balance');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('pending_withdrawal');
        });
    }
};
