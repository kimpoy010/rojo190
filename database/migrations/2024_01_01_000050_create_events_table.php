<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('arena')->nullable();
            $table->dateTime('date')->nullable();
            $table->enum('status', ['upcoming', 'live', 'completed', 'cancelled'])->default('upcoming');
            $table->string('stream_url')->nullable();
            $table->boolean('draw_enabled')->default(true);
            // Purely cosmetic scaling factor applied to displayed pool totals; never
            // touches real money (bets/payouts are always computed off raw amounts).
            $table->decimal('multiplier', 8, 2)->default(1);
            $table->decimal('bet_limit', 10, 2)->nullable();
            $table->string('label_meron')->default('Meron');
            $table->string('label_wala')->default('Wala');
            $table->string('label_draw')->default('Draw');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
