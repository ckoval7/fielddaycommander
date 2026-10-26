<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('operating_sessions', 'previous_session_id')) {
            return;
        }

        Schema::table('operating_sessions', function (Blueprint $table) {
            $table->foreignId('previous_session_id')->nullable()->after('operator_user_id')
                ->constrained('operating_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('operating_sessions', 'previous_session_id')) {
            return;
        }

        Schema::table('operating_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_session_id');
        });
    }
};
