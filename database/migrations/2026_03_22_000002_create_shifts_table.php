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
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->foreignId('shift_role_id')->constrained('shift_roles')->cascadeOnDelete();
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->unsignedInteger('capacity')->default(1);
            $table->boolean('is_open')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('event_configuration_id');
            $table->index('shift_role_id');
            $table->index(['start_time', 'end_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
