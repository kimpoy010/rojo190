<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teller_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teller_id')->constrained('users')->cascadeOnDelete();
            // Cash physically counted into the drawer when the shift began.
            $table->decimal('starting_cash', 15, 2);
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            // Cash physically counted out of the drawer at end of shift —
            // null until the shift is closed.
            $table->decimal('ending_cash', 15, 2)->nullable();
            // starting_cash + approved deposits - approved withdrawals,
            // snapshotted at close time so the report never drifts even if
            // later transactions are somehow attributed to this shift.
            $table->decimal('expected_cash', 15, 2)->nullable();
            // ending_cash - expected_cash. Positive = over, negative = short.
            $table->decimal('variance', 15, 2)->nullable();
            $table->timestamps();

            $table->index(['teller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teller_shifts');
    }
};
