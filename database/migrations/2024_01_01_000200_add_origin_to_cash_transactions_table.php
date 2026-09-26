<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            // How the request was initiated: 'teller' (player showed a QR
            // at the counter — the historical default) or 'rfid' (player
            // tapped their card at a self-service kiosk). Purely
            // informational — approval flow is identical either way.
            $table->string('origin')->default('teller')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
