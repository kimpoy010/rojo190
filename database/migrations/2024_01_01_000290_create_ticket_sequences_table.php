<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the counter bet ticket_code with a plain sequential number
 * (0000000001, 0000000002, ...) instead of a random alphanumeric string —
 * easier for a teller to read back over the counter or type manually into
 * the redeem lookup. A single locked row is incremented per ticket; see
 * BettingService::generateTicketCode().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('next_value')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sequences');
    }
};
