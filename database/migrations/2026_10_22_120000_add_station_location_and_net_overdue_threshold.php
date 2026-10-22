<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each station or post is, in plain words, and how long a net lets
     * a station stay quiet before net control sees it as overdue.
     */
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('location', 150)->nullable()->after('tactical_callsign');
        });

        Schema::table('nets', function (Blueprint $table) {
            $table->unsignedSmallInteger('overdue_after_minutes')->nullable()->after('net_control_station');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('location');
        });

        Schema::table('nets', function (Blueprint $table) {
            $table->dropColumn('overdue_after_minutes');
        });
    }
};
