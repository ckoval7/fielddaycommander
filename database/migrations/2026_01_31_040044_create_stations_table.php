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
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->foreignId('radio_equipment_id')->nullable()->constrained('equipment');

            $table->string('name', 50);
            $table->string('hostname', 50)->nullable();
            $table->text('power_source_description')->nullable();
            $table->string('power_source', 20)->nullable();
            $table->boolean('is_gota')->default(false);
            $table->boolean('is_vhf_only')->default(false);
            $table->boolean('is_satellite')->default(false);
            $table->integer('max_power_watts')->default(100);

            $table->timestamps();
            $table->softDeletes();

            $table->index('event_configuration_id');
            $table->index('is_gota');
            $table->unique(['event_configuration_id', 'radio_equipment_id'], 'stations_event_radio_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
