<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational periods: named time blocks on an event, and a per-period
 * subject on checklist runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_configuration_id')->constrained('event_configurations')->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->timestamps();

            $table->index(['event_configuration_id', 'start_time']);
        });

        Schema::table('checklist_runs', function (Blueprint $table) {
            $table->foreignId('operational_period_id')->nullable()->after('user_id')->constrained('operational_periods')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checklist_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operational_period_id');
        });

        Schema::dropIfExists('operational_periods');
    }
};
