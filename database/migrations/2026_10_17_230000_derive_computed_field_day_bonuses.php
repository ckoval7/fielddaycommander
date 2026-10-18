<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take the computed and checklist Field Day bonuses off the Manual Bonus Claims card.
 *
 * Emergency Power and Satellite QSO are computed from the event's power
 * settings and contacts, so a manual claim did nothing. Safety Officer and
 * Site Responsibilities are earned by completing their checklist, so a manual
 * claim skipped it. All four move to derived for every Field Day rules
 * version, and any safety bonus whose checklist isn't complete is removed.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const DERIVED_CODES = ['emergency_power', 'satellite_qso', 'safety_officer', 'site_responsibilities'];

    /** @var array<string, string> Bonus code => checklist type. */
    private const CHECKLIST_TYPES = [
        'safety_officer' => 'safety_officer',
        'site_responsibilities' => 'site_responsibilities',
    ];

    public function up(): void
    {
        $this->setTriggerType('derived');
        $this->removeUnearnedSafetyBonuses();
    }

    public function down(): void
    {
        $this->setTriggerType('manual');
    }

    private function setTriggerType(string $triggerType): void
    {
        $eventTypeId = DB::table('event_types')->where('code', 'FD')->value('id');

        if (! $eventTypeId) {
            return;
        }

        DB::table('bonus_types')
            ->where('event_type_id', $eventTypeId)
            ->whereIn('code', self::DERIVED_CODES)
            ->update(['trigger_type' => $triggerType]);
    }

    /**
     * Delete safety bonus claims whose checklist gate isn't met.
     *
     * Mirrors SiteSafetyChecklist::checklistGateMet(): Safety Officer needs every
     * item done; Site Responsibilities needs every required item and more than
     * half of all items.
     */
    private function removeUnearnedSafetyBonuses(): void
    {
        $bonuses = DB::table('event_bonuses')
            ->join('bonus_types', 'bonus_types.id', '=', 'event_bonuses.bonus_type_id')
            ->whereIn('bonus_types.code', array_keys(self::CHECKLIST_TYPES))
            ->select('event_bonuses.id', 'event_bonuses.event_configuration_id', 'bonus_types.code')
            ->get();

        foreach ($bonuses as $bonus) {
            if (! $this->checklistGateMet((int) $bonus->event_configuration_id, self::CHECKLIST_TYPES[$bonus->code])) {
                DB::table('event_bonuses')->where('id', $bonus->id)->delete();
            }
        }
    }

    private function checklistGateMet(int $eventConfigurationId, string $checklistType): bool
    {
        $items = DB::table('safety_checklist_items')
            ->leftJoin('safety_checklist_entries', 'safety_checklist_entries.safety_checklist_item_id', '=', 'safety_checklist_items.id')
            ->where('safety_checklist_items.event_configuration_id', $eventConfigurationId)
            ->where('safety_checklist_items.checklist_type', $checklistType)
            ->select('safety_checklist_items.is_required', 'safety_checklist_entries.is_completed')
            ->get();

        if ($items->isEmpty()) {
            return false;
        }

        $completed = $items->filter(fn (object $item): bool => (bool) $item->is_completed);

        if ($checklistType === 'safety_officer') {
            return $completed->count() === $items->count();
        }

        $requiredTotal = $items->filter(fn (object $item): bool => (bool) $item->is_required)->count();
        $requiredCompleted = $completed->filter(fn (object $item): bool => (bool) $item->is_required)->count();

        return $requiredCompleted === $requiredTotal && $completed->count() > ($items->count() / 2);
    }
};
