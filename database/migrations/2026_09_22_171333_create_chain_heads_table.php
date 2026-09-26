<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The tail of each independent hash chain (see HasHashChain) — one
        // row per (chain table, scope), locked with lockForUpdate() before
        // every append so concurrent writes to the same scope (e.g. two
        // bets from the same wallet) can't race on "what's the previous
        // hash" and silently fork the chain.
        Schema::create('chain_heads', function (Blueprint $table) {
            $table->id();
            $table->string('chain', 64);
            $table->string('scope', 64);
            $table->string('last_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['chain', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chain_heads');
    }
};
