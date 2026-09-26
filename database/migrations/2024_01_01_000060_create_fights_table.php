<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('fight_number');
            $table->enum('status', ['pending', 'open', 'closed', 'declared', 'cancelled'])->default('pending');
            $table->boolean('draw_enabled')->default(true);
            $table->enum('winner', ['meron', 'wala', 'draw'])->nullable();
            $table->timestamp('declared_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'fight_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fights');
    }
};
