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
        Schema::create('images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->nullable()->constrained('event_configurations')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users');

            $table->string('filename', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 50);
            $table->integer('file_size_bytes')->nullable();
            $table->text('description')->nullable();
            $table->text('caption')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('event_configuration_id');
            $table->index('uploaded_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
