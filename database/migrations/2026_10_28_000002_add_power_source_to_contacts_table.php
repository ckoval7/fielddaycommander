<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record the station's power source on each contact, so switching a station
 * back from commercial mains doesn't erase the contacts made on mains.
 *
 * Existing contacts take their station's current power source, which is what
 * scoring used for them until now. Stale emergency_power bonus rows are
 * removed: that bonus is always computed from the event's power sources, and
 * a leftover row kept showing on the submission sheet after the bonus was lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('power_source', 32)->nullable()->after('power_watts');
        });

        DB::table('contacts')
            ->whereNull('power_source')
            ->whereNotNull('operating_session_id')
            ->update([
                'power_source' => DB::raw(
                    '(SELECT stations.power_source FROM operating_sessions'
                    .' INNER JOIN stations ON stations.id = operating_sessions.station_id'
                    .' WHERE operating_sessions.id = contacts.operating_session_id)'
                ),
            ]);

        DB::table('event_bonuses')
            ->whereIn('bonus_type_id', DB::table('bonus_types')->where('code', 'emergency_power')->select('id'))
            ->delete();
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('power_source');
        });
    }
};
