<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_logger_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->string('listener_type', 20);
            $table->boolean('is_enabled')->default(false);
            $table->integer('port')->default(12060);
            $table->unsignedInteger('pid')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['event_configuration_id', 'listener_type'], 'ext_logger_settings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_logger_settings');
    }
};
