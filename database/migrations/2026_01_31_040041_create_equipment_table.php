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
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('managed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('make', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->enum('type', ['radio', 'antenna', 'amplifier', 'computer', 'power_supply', 'accessory', 'tool', 'furniture', 'other']);
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->decimal('value_usd', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('power_output_watts')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_user_id');
            $table->index('owner_organization_id');
            $table->index('managed_by_user_id');
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
