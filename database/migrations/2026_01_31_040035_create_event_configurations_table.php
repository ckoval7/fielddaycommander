<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('event_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users');

            // Core Configuration
            $table->string('callsign', 20);
            $table->string('club_name', 100)->nullable();
            $table->foreignId('section_id')->constrained('sections');
            $table->foreignId('operating_class_id')->constrained('operating_classes');
            $table->integer('transmitter_count')->default(1);

            // GOTA Station
            $table->boolean('has_gota_station')->default(false);
            $table->string('gota_callsign', 20)->nullable();

            // Power Configuration
            $table->integer('max_power_watts')->default(100);
            $table->enum('power_multiplier', ['1', '2', '5'])->default('2');

            // Power Sources (for bonus calculations)
            $table->boolean('uses_commercial_power')->default(false);
            $table->boolean('uses_generator')->default(false);
            $table->boolean('uses_battery')->default(false);
            $table->boolean('uses_solar')->default(false);
            $table->boolean('uses_wind')->default(false);
            $table->boolean('uses_water')->default(false);
            $table->boolean('uses_methane')->default(false);
            $table->string('uses_other_power', 100)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('event_id');
            $table->unique(['event_id', 'callsign']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_configurations');
    }
};
