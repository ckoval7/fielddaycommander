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
        Schema::table('equipment', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['owner_user_id']);

            // Make the column nullable
            $table->foreignId('owner_user_id')
                ->nullable()
                ->change();

            // Re-add the foreign key constraint
            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove all equipment with null owner_user_id before reverting
        \DB::table('equipment')->whereNull('owner_user_id')->delete();

        Schema::table('equipment', function (Blueprint $table) {
            // Drop the foreign key constraint
            $table->dropForeign(['owner_user_id']);

            // Make the column not nullable
            $table->foreignId('owner_user_id')
                ->nullable(false)
                ->change();

            // Re-add the foreign key with cascade delete
            $table->foreign('owner_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
