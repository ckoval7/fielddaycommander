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
        Schema::create('adif_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('event_configuration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('status')->default('pending_mapping');
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('mapped_records')->default(0);
            $table->unsignedInteger('imported_records')->default(0);
            $table->unsignedInteger('skipped_records')->default(0);
            $table->unsignedInteger('merged_records')->default(0);
            $table->json('field_mapping')->nullable();
            $table->json('station_mapping')->nullable();
            $table->json('operator_mapping')->nullable();
            $table->json('inconsistencies_resolved')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adif_imports');
    }
};
