<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The card ID read off a player's assigned RFID tag. Nullable —
            // most players may never get a physical card — and unique so a
            // tag can only ever resolve to one account.
            $table->string('rfid_uid')->nullable()->unique()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('rfid_uid');
        });
    }
};
