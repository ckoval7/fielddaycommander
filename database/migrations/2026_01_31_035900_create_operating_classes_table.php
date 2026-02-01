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
        Schema::create('operating_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_type_id')->constrained('event_types')->cascadeOnDelete();
            $table->string('code', 10);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('allows_gota')->default(false);
            $table->boolean('allows_free_vhf')->default(false);
            $table->integer('max_power_watts')->nullable();
            $table->boolean('requires_emergency_power')->default(false);
            $table->timestamps();

            $table->unique(['event_type_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operating_classes');
    }
};
