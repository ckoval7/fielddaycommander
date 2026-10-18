<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a marker for registrations awaiting administrator approval.
     *
     * Backfill: before this column existed, an account awaiting approval was
     * only recognisable as "locked and never signed in". Those accounts are
     * marked pending, except ghost accounts (created locked on purpose) and
     * accounts with a `user.locked` audit entry (locked by an administrator).
     * An admin-locked account that predates audit logging is still marked
     * pending, which matches the old login message for it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('approval_requested_at')->nullable()->after('account_locked_at');
        });

        DB::table('users')
            ->whereNotNull('account_locked_at')
            ->whereNull('last_login_at')
            ->where('is_ghost', false)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('audit_logs')
                    ->where('audit_logs.action', 'user.locked')
                    ->where('audit_logs.auditable_type', User::class)
                    ->whereColumn('audit_logs.auditable_id', 'users.id');
            })
            ->update(['approval_requested_at' => DB::raw('account_locked_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('approval_requested_at');
        });
    }
};
