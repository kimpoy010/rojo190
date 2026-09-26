<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fight_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bet_id')->constrained()->cascadeOnDelete();
            $table->enum('side', ['meron', 'wala', 'draw'])->nullable();
            $table->decimal('matched_amount', 15, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->timestamp('credited_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_logs');
    }
};
