<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfid_readers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfid_terminal_id')->constrained()->cascadeOnDelete();
            // The ESP32's own WiFi MAC address (identical firmware on every
            // board — nothing role-specific is flashed). Auto-registers the
            // first time a device posts a scan; unique globally since a MAC
            // is unique per board regardless of which terminal it's on.
            $table->string('device_id', 64)->unique();
            // Null until a superadmin assigns it — a freshly-registered
            // reader can't be used to bet/top-up until it has a role.
            $table->enum('role', ['meron', 'wala', 'topup'])->nullable();
            $table->string('label')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfid_readers');
    }
};
