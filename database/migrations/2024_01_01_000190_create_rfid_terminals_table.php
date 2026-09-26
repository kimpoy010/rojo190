<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfid_terminals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Bearer credential the local serial-to-API bridge authenticates
            // with, and also the unguessable segment of the kiosk's public
            // URL — same "possession of the token is the credential"
            // pattern already used for cash_transactions.code.
            $table->string('token', 40)->unique();
            // Which event this terminal is currently taking bets/top-ups
            // for. Null until a superadmin assigns one.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfid_terminals');
    }
};
