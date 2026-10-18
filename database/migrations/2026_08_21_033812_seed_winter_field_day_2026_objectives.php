<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the twelve Winter Field Day 2026 objectives.
 *
 * Source: WFDA Standard Operating Procedure (2026), "Objective & Multipliers".
 *
 * Before this migration, the only WFD rows were three placeholder point-bonuses
 * seeded at rules_version 2025, which `seed_2026_bonus_types_from_2025` then
 * cloned forward to 2026. Those clones describe awards that do not exist in the
 * WFD rulebook and carry point values instead of multipliers, so they are
 * corrected in place here rather than deleted — an id may already be referenced
 * by an event_bonuses claim row. Codes with no 2026 equivalent are deactivated
 * for the same reason.
 *
 * The 2025 rows themselves are left untouched: frozen versions are never edited.
 * No WFD event could have been scored against them (the RuleSetFactory had no
 * WFD entry and threw), so nothing historical depends on their values.
 */
return new class extends Migration
{
    private const VERSION = '2026';

    /**
     * @return array<int, array<string, mixed>>
     */
    private function objectives(): array
    {
        return [
            ['code' => 'alternative_power', 'name' => 'Operate 100% on Alternative Power', 'objective_multiplier' => 1, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Operate exclusively on power not connected to the commercial grid. Lights and HVAC are exempt.'],
            ['code' => 'away_from_home', 'name' => 'Operate Away From Home', 'objective_multiplier' => 3, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Set up the field station more than half a mile from home.'],
            ['code' => 'multiple_antennas', 'name' => 'Deploy Multiple Antennas', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Deploy two or more not-previously-installed antennas and make at least one contact on each.'],
            ['code' => 'fm_satellite', 'name' => 'FM Satellite Contact', 'objective_multiplier' => 2, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Make at least one FM satellite contact during the operating period.'],
            ['code' => 'ssb_cw_satellite', 'name' => 'SSB or CW Satellite Contact', 'objective_multiplier' => 3, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Make at least one satellite contact using SSB or CW.'],
            ['code' => 'winlink_email', 'name' => 'Send and Receive a Winlink Email', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => true, 'description' => 'Send and receive at least one email via a winlink.org address over amateur RF, timestamped within the operational period.'],
            ['code' => 'special_bulletin', 'name' => 'Copy the WFD Special Bulletin', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => true, 'description' => 'Accurately copy the WFD Special Bulletin and submit the copy with the log.'],
            ['code' => 'six_bands', 'name' => 'Three Contacts on Six Bands', 'objective_multiplier' => 6, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Log at least three contacts on each of six or more different bands.'],
            ['code' => 'twelve_bands', 'name' => 'Three Contacts on Twelve Bands', 'objective_multiplier' => 6, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Log at least three contacts on each of twelve or more different bands. The six-band objective counts toward this one.'],
            ['code' => 'multiple_modes', 'name' => 'Use Multiple Modes', 'objective_multiplier' => 2, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Work at least two of the three mode groups (CW, Phone, Digital). Using all three earns no more.'],
            ['code' => 'qrp', 'name' => 'Operate the Event QRP', 'objective_multiplier' => 4, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Every station runs 10 W or less on Phone, or 5 W or less on CW and Digital, for the whole time operated.'],
            ['code' => 'six_continuous_hours', 'name' => 'Operate Six Continuous Hours', 'objective_multiplier' => 2, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Staff the radio for six continuous hours, monitoring and ready to respond.'],
        ];
    }

    public function up(): void
    {
        $eventTypeId = DB::table('event_types')->where('code', 'WFD')->value('id');

        if (! $eventTypeId) {
            return;
        }

        $now = now();
        $codes = [];

        foreach ($this->objectives() as $objective) {
            $codes[] = $objective['code'];

            DB::table('bonus_types')->updateOrInsert(
                [
                    'event_type_id' => $eventTypeId,
                    'rules_version' => self::VERSION,
                    'code' => $objective['code'],
                ],
                [
                    'name' => $objective['name'],
                    'description' => $objective['description'],
                    'trigger_type' => $objective['trigger_type'],
                    'base_points' => 0,
                    'objective_multiplier' => $objective['objective_multiplier'],
                    'is_per_transmitter' => false,
                    'is_per_occurrence' => false,
                    'max_points' => 0,
                    'max_occurrences' => 1,
                    'requires_proof' => $objective['requires_proof'],
                    'eligible_classes' => null,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        // Placeholder rows cloned forward from 2025 that the 2026 rulebook has
        // no objective for. Deactivated rather than deleted so any claim row
        // pointing at them stays resolvable.
        DB::table('bonus_types')
            ->where('event_type_id', $eventTypeId)
            ->where('rules_version', self::VERSION)
            ->whereNotIn('code', $codes)
            ->update(['is_active' => false, 'updated_at' => $now]);
    }

    public function down(): void
    {
        $eventTypeId = DB::table('event_types')->where('code', 'WFD')->value('id');

        if (! $eventTypeId) {
            return;
        }

        DB::table('bonus_types')
            ->where('event_type_id', $eventTypeId)
            ->where('rules_version', self::VERSION)
            ->whereIn('code', array_column($this->objectives(), 'code'))
            ->delete();
    }
};
