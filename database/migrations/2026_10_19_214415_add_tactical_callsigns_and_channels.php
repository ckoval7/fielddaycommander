<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tactical callsigns for stations and shift roles, and the channel each
     * station works, for the net log and the ICS-205 radio plan.
     */
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->string('tactical_callsign', 50)->nullable()->after('name');
            $table->string('rx_frequency', 20)->nullable()->after('tactical_callsign');
            $table->string('tx_frequency', 20)->nullable()->after('rx_frequency');
            $table->string('tone', 20)->nullable()->after('tx_frequency');
            $table->string('comms_mode', 20)->nullable()->after('tone');
        });

        Schema::table('shift_roles', function (Blueprint $table) {
            $table->string('tactical_callsign', 50)->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn(['tactical_callsign', 'rx_frequency', 'tx_frequency', 'tone', 'comms_mode']);
        });

        Schema::table('shift_roles', function (Blueprint $table) {
            $table->dropColumn('tactical_callsign');
        });
    }
};
