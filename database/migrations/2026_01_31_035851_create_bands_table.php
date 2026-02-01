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
        Schema::create('bands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 10)->unique();
            $table->integer('meters')->nullable();
            $table->decimal('frequency_mhz', 10, 4)->nullable();
            $table->boolean('is_hf')->default(true);
            $table->boolean('is_vhf_uhf')->default(false);
            $table->boolean('is_satellite')->default(false);
            $table->boolean('allowed_fd')->default(true);
            $table->boolean('allowed_wfd')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('allowed_fd');
            $table->index('allowed_wfd');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bands');
    }
};
