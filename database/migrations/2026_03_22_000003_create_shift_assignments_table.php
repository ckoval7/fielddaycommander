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
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->enum('status', ['scheduled', 'checked_in', 'checked_out', 'no_show'])->default('scheduled');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->enum('signup_type', ['assigned', 'self_signup'])->default('assigned');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['shift_id', 'user_id']);
            $table->index('shift_id');
            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
    }
};
