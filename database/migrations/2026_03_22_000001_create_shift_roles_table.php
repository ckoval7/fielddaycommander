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
        Schema::create('shift_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('bonus_points')->nullable();
            $table->boolean('requires_confirmation')->default(false);
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('event_configuration_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_roles');
    }
};
