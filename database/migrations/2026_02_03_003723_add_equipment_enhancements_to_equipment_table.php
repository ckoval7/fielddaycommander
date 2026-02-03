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
            // Foreign keys for ownership
            $table->unsignedBigInteger('owner_organization_id')
                ->nullable()
                ->after('owner_user_id');

            $table->unsignedBigInteger('managed_by_user_id')
                ->nullable()
                ->after('owner_organization_id');

            // Equipment details
            $table->json('tags')
                ->nullable()
                ->after('description');

            $table->decimal('value_usd', 10, 2)
                ->nullable()
                ->after('tags');

            $table->text('notes')
                ->nullable()
                ->after('value_usd');

            // Equipment specifications
            $table->unsignedInteger('power_output_watts')
                ->nullable()
                ->after('notes');

            $table->string('photo_path')
                ->nullable()
                ->after('power_output_watts');

            // Add indexes
            $table->index('owner_organization_id');
            $table->index('managed_by_user_id');

            // Add foreign keys
            $table->foreign('owner_organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->foreign('managed_by_user_id')
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
        Schema::table('equipment', function (Blueprint $table) {
            // Drop foreign keys
            $table->dropForeign(['owner_organization_id']);
            $table->dropForeign(['managed_by_user_id']);

            // Drop columns (which also removes indexes)
            $table->dropColumn([
                'owner_organization_id',
                'managed_by_user_id',
                'tags',
                'value_usd',
                'notes',
                'power_output_watts',
                'photo_path',
            ]);
        });
    }
};
