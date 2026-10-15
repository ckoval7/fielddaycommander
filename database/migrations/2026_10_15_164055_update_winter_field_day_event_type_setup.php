<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTION = 'Winter Field Day Association event - held the 4th full weekend in January';

    private const PREVIOUS_DESCRIPTION = 'Winter Field Day Association event - held the last full weekend in January';

    /**
     * Set WFD setup to open 24 hours before the 16:00 UTC Saturday start, per the WFD SOP.
     */
    public function up(): void
    {
        DB::table('event_types')
            ->where('code', 'WFD')
            ->update([
                'description' => self::DESCRIPTION,
                'setup_offset_hours' => 24,
            ]);
    }

    /**
     * Restore the previous WFD description and remove the setup offset.
     */
    public function down(): void
    {
        DB::table('event_types')
            ->where('code', 'WFD')
            ->update([
                'description' => self::PREVIOUS_DESCRIPTION,
                'setup_offset_hours' => null,
            ]);
    }
};
