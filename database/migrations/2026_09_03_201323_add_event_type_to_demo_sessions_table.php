<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which event a demo visitor chose so the analytics dashboard can
     * break usage down by rulebook. Existing rows predate the picker and were
     * all ARRL Field Day sandboxes.
     */
    public function up(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->string('event_type', 10)->default('FD')->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('demo_sessions', function (Blueprint $table) {
            $table->dropColumn('event_type');
        });
    }
};
