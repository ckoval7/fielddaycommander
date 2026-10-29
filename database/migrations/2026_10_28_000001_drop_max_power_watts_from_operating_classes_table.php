<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operating-class power limit is a rule of the event's rules version
 * (App\Scoring\ClassPowerLimits), so the unused per-class column goes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('operating_classes', 'max_power_watts')) {
            return;
        }

        Schema::table('operating_classes', function (Blueprint $table) {
            $table->dropColumn('max_power_watts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('operating_classes', 'max_power_watts')) {
            return;
        }

        Schema::table('operating_classes', function (Blueprint $table) {
            $table->integer('max_power_watts')->nullable()->after('allows_free_vhf');
        });
    }
};
