<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert media_publicity from a derived bonus to a manual claim.
 *
 * This migration also removes any event_bonuses rows that were auto-written by
 * the old derived pipeline (GuestbookBonusSyncService, since deleted). Those
 * rows would otherwise surface in the admin UI as already-claimed without any
 * explicit admin action, which is the opposite of what "manual" means. After
 * this migration, admins must re-claim media_publicity through the normal
 * manual-claim flow.
 */
return new class extends Migration
{
    private const NEW_DESCRIPTION = 'Publicity received from local media';

    private const OLD_DESCRIPTION = 'Official visit by broadcast or print media representative';

    public function up(): void
    {
        $bonusTypeIds = DB::table('bonus_types')
            ->where('code', 'media_publicity')
            ->pluck('id');

        if ($bonusTypeIds->isNotEmpty()) {
            DB::table('event_bonuses')
                ->whereIn('bonus_type_id', $bonusTypeIds)
                ->delete();
        }

        DB::table('bonus_types')
            ->where('code', 'media_publicity')
            ->update([
                'trigger_type' => 'manual',
                'description' => self::NEW_DESCRIPTION,
            ]);
    }

    public function down(): void
    {
        DB::table('bonus_types')
            ->where('code', 'media_publicity')
            ->update([
                'trigger_type' => 'derived',
                'description' => self::OLD_DESCRIPTION,
            ]);
    }
};
