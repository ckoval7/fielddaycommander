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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('call_sign', 12);
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('requires_password_change')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('two_factor_bypass_enabled')->default(false);
            $table->timestamp('two_factor_bypass_expires_at')->nullable();
            $table->string('two_factor_bypass_reason')->nullable();
            $table->enum('license_class', ['Technician', 'General', 'Advanced', 'Extra'])->nullable();
            $table->enum('user_role', ['user', 'admin', 'locked'])->default('user');
            $table->timestamp('account_locked_at')->nullable();
            $table->integer('failed_login_attempts')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->string('preferred_timezone')->nullable();
            $table->json('notification_preferences')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // Indexes (no unique constraints - enforced at validation layer for soft delete support)
            $table->index('call_sign');
            $table->index('user_role');
            $table->index('account_locked_at');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
