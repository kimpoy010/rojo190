<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['agent_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commission_rates');
    }
};
