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
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('make', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->enum('type', ['radio', 'antenna', 'amplifier', 'tuner', 'power_supply', 'computer', 'other']);
            $table->text('description')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_user_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
