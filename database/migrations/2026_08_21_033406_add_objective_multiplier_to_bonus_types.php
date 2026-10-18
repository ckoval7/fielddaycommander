<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a bonus_types row carry a multiplier instead of a point value.
 *
 * ARRL Field Day awards are additive point bonuses. Winter Field Day awards
 * "objectives", each worth an Objective Multiplier (OM) that is summed and
 * applied as `QSO points x (OM + 1)` — no points of their own. Both share the
 * same versioning, claim, proof and reconcile machinery, so they share the
 * table; only the value column differs.
 *
 * Null means "this award is scored in points" (every Field Day row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonus_types', function (Blueprint $table) {
            $table->unsignedInteger('objective_multiplier')
                ->nullable()
                ->after('base_points');
        });
    }

    public function down(): void
    {
        Schema::table('bonus_types', function (Blueprint $table) {
            $table->dropColumn('objective_multiplier');
        });
    }
};
