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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->foreignId('operating_session_id')->constrained('operating_sessions')->cascadeOnDelete();
            $table->foreignId('logger_user_id')->constrained('users');
            $table->foreignId('band_id')->constrained('bands');
            $table->foreignId('mode_id')->constrained('modes');

            // QSO Details
            $table->timestamp('qso_time')->nullable();
            $table->string('callsign', 20);
            $table->foreignId('section_id')->nullable()->constrained('sections');
            $table->string('received_exchange', 50)->nullable();
            $table->integer('power_watts')->nullable();

            // GOTA-specific fields
            $table->boolean('is_gota_contact')->default(false);
            $table->string('gota_operator_first_name', 50)->nullable();
            $table->string('gota_operator_last_name', 50)->nullable();
            $table->string('gota_operator_callsign', 20)->nullable();
            $table->foreignId('gota_coach_user_id')->nullable()->constrained('users');

            // Special Contact Types
            $table->boolean('is_natural_power')->default(false);
            $table->boolean('is_satellite')->default(false);
            $table->string('satellite_name', 50)->nullable();

            // Scoring
            $table->integer('points')->default(1);
            $table->boolean('is_duplicate')->default(false);
            $table->boolean('is_transcribed')->default(false);
            $table->foreignId('duplicate_of_contact_id')->nullable()->constrained('contacts');

            // Notes
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // CRITICAL INDEXES
            $table->index('event_configuration_id');
            $table->index('operating_session_id');
            $table->index(['callsign', 'band_id', 'mode_id']);
            $table->index('qso_time');
            $table->index('is_duplicate');
            $table->index('is_transcribed');
            $table->index('is_gota_contact');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
