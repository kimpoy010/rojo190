<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cockpit_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // A preset's cockpits, in the order they were added — that order
        // is also what decides an event's "primary" cockpit (see
        // Event::primaryCockpit()), the one whose feed previews the event
        // before any fight has its own cockpit assigned.
        Schema::create('cockpit_cockpit_preset', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cockpit_preset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cockpit_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['cockpit_preset_id', 'cockpit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cockpit_cockpit_preset');
        Schema::dropIfExists('cockpit_presets');
    }
};
