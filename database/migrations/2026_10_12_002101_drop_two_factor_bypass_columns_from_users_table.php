<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the never-honoured 2FA bypass columns. The emergency reset
     * command now clears the enrolled 2FA factor instead.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_bypass_enabled',
                'two_factor_bypass_expires_at',
                'two_factor_bypass_reason',
            ]);
        });
    }

    /**
     * Restore the bypass columns.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('two_factor_bypass_enabled')->default(false);
            $table->timestamp('two_factor_bypass_expires_at')->nullable();
            $table->string('two_factor_bypass_reason')->nullable();
        });
    }
};
