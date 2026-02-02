<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration removes unique constraints on call_sign and email
     * to allow reuse when users are soft-deleted. Uniqueness is enforced
     * at the validation layer using Rule::unique()->withoutTrashed().
     *
     * MySQL/MariaDB doesn't support partial/filtered unique indexes like PostgreSQL,
     * so we remove the database-level constraint and rely on application-level
     * validation, which is Laravel's recommended pattern for soft deletes.
     */
    public function up(): void
    {
        Schema::table('users', function ($table) {
            // Drop unique constraints - uniqueness will be enforced by validation
            $table->dropUnique('users_call_sign_unique');
            $table->dropUnique('users_email_unique');

            // Keep regular indexes for query performance
            // These already exist from the original migration
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function ($table) {
            // Restore original unique constraints
            $table->unique('call_sign', 'users_call_sign_unique');
            $table->unique('email', 'users_email_unique');
        });
    }
};
