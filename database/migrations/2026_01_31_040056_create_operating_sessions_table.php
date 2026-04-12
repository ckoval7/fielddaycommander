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
        Schema::create('operating_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignId('operator_user_id')->nullable()->constrained('users');
            $table->foreignId('band_id')->nullable()->constrained('bands');
            $table->foreignId('mode_id')->nullable()->constrained('modes');

            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->integer('qso_count')->default(0);
            $table->boolean('is_transcription')->default(false);
            $table->boolean('is_supervised')->default(false);
            $table->integer('power_watts')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('external_source', 20)->nullable();

            $table->timestamps();

            $table->index('station_id');
            $table->index('operator_user_id');
            $table->index(['start_time', 'end_time']);
            $table->index('power_watts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operating_sessions');
    }
};
