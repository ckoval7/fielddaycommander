<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Flag existing contacts on the Satellite band or a Satellite Station as satellite QSOs.
 *
 * No logging path set contacts.is_satellite, so the Field Day Satellite QSO
 * bonus was never awarded. Contacts now derive the flag when saved; this
 * catches the ones logged before that.
 */
return new class extends Migration
{
    public function up(): void
    {
        $satelliteBandIds = DB::table('bands')->where('is_satellite', true)->pluck('id');

        $satelliteSessionIds = DB::table('operating_sessions')
            ->join('stations', 'stations.id', '=', 'operating_sessions.station_id')
            ->where('stations.is_satellite', true)
            ->pluck('operating_sessions.id');

        DB::table('contacts')
            ->where('is_satellite', false)
            ->where(fn ($query) => $query
                ->whereIn('band_id', $satelliteBandIds)
                ->orWhereIn('operating_session_id', $satelliteSessionIds))
            ->update(['is_satellite' => true]);
    }

    public function down(): void
    {
        // The flag can't be told apart from one set by the logging paths.
    }
};
