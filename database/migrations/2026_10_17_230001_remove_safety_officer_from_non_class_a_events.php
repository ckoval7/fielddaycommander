<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the Safety Officer checklist and bonus from events that aren't Class A.
 *
 * ARRL 7.3.17 limits the Safety Officer bonus to Class A, but Class F events
 * used to get its checklist and earn its 100 points alongside Site
 * Responsibilities. Their Safety Officer bonuses and checklist items are
 * removed; checklist entries cascade with their items.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nonClassAConfigIds = DB::table('event_configurations')
            ->join('operating_classes', 'operating_classes.id', '=', 'event_configurations.operating_class_id')
            ->get(['event_configurations.id', 'operating_classes.code'])
            ->reject(fn (object $config): bool => preg_replace('/\d+/', '', $config->code) === 'A')
            ->pluck('id');

        if ($nonClassAConfigIds->isEmpty()) {
            return;
        }

        $safetyOfficerTypeIds = DB::table('bonus_types')->where('code', 'safety_officer')->pluck('id');

        DB::table('event_bonuses')
            ->whereIn('event_configuration_id', $nonClassAConfigIds)
            ->whereIn('bonus_type_id', $safetyOfficerTypeIds)
            ->delete();

        DB::table('safety_checklist_items')
            ->whereIn('event_configuration_id', $nonClassAConfigIds)
            ->where('checklist_type', 'safety_officer')
            ->delete();
    }

    public function down(): void
    {
        // Removed claims and checklist items can't be restored.
    }
};
