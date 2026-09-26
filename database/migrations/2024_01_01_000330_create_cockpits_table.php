<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cockpits', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Nullable — a cockpit can exist (and be assigned to fights)
            // before anyone's wired up its camera.
            $table->string('stream_url')->nullable();
            $table->timestamps();
        });

        // The arena this app was built for runs 3 cockpits in parallel —
        // seeded so a declarator has something to assign on day one.
        // Superadmin can rename these or add more from the cockpits screen.
        foreach ([1, 2, 3] as $number) {
            DB::table('cockpits')->insert([
                'name' => "Cockpit {$number}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cockpits');
    }
};
