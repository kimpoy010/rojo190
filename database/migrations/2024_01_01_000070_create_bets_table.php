<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pool-sabong bets are always fully matched against the pool immediately
        // (no peer-to-peer matching), so there is no need for the matched/unmatched
        // split the reference platform uses for its odds-tier games.
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fight_id')->constrained()->cascadeOnDelete();
            $table->enum('side', ['meron', 'wala', 'draw']);
            $table->decimal('amount', 10, 2);
            $table->enum('status', ['matched', 'settled', 'refunded'])->default('matched');
            $table->decimal('payout', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['fight_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};
