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
        Schema::create('event_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->foreignId('bonus_type_id')->constrained('bonus_types')->cascadeOnDelete();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users');

            $table->integer('quantity')->default(1);
            $table->integer('calculated_points')->default(0);
            $table->text('notes')->nullable();
            $table->string('proof_file_path')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index('event_configuration_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_bonuses');
    }
};
