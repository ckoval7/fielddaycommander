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
        Schema::create('adif_import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adif_import_id')->constrained()->cascadeOnDelete();
            $table->json('raw_data');
            $table->string('callsign')->nullable();
            $table->dateTime('qso_time')->nullable();
            $table->string('band_name')->nullable();
            $table->string('mode_name')->nullable();
            $table->string('section_code')->nullable();
            $table->string('exchange_class')->nullable();
            $table->string('station_identifier')->nullable();
            $table->string('operator_callsign')->nullable();
            $table->foreignId('band_id')->nullable()->constrained();
            $table->foreignId('mode_id')->nullable()->constrained();
            $table->foreignId('section_id')->nullable()->constrained();
            $table->unsignedBigInteger('station_id')->nullable();
            $table->unsignedBigInteger('operator_user_id')->nullable();
            $table->unsignedBigInteger('matched_contact_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('station_id')->references('id')->on('stations')->nullOnDelete();
            $table->foreign('operator_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('matched_contact_id')->references('id')->on('contacts')->nullOnDelete();

            $table->index('callsign');
            $table->index('qso_time');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adif_import_records');
    }
};
