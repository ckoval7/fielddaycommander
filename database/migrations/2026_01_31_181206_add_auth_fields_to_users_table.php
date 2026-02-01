<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Emergency 2FA Bypass
            $table->boolean('two_factor_bypass_enabled')->default(false)->after('two_factor_confirmed_at');
            $table->timestamp('two_factor_bypass_expires_at')->nullable()->after('two_factor_bypass_enabled');
            $table->string('two_factor_bypass_reason')->nullable()->after('two_factor_bypass_expires_at');

            // Password Management
            $table->boolean('requires_password_change')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('requires_password_change');

            // Account Security
            $table->timestamp('account_locked_at')->nullable()->after('user_role');
            $table->integer('failed_login_attempts')->default(0)->after('account_locked_at');

            // Indexes
            $table->index('account_locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_bypass_enabled',
                'two_factor_bypass_expires_at',
                'two_factor_bypass_reason',
                'requires_password_change',
                'password_changed_at',
                'account_locked_at',
                'failed_login_attempts',
            ]);
        });
    }
};
