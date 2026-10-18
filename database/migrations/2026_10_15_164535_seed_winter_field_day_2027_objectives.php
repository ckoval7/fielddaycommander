<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the thirteen Winter Field Day 2027 objectives.
 *
 * Source: WFDA "Winter Field Day 2027" rules, Version 3, 04 OCT 2026,
 * "Objective & Multipliers".
 *
 * Production updates run migrations but not seeders, so the rows the
 * BonusTypeSeeder defines for 2027 are inserted here too. Existing rows are
 * left untouched, so re-running this is harmless and never alters a claim's
 * bonus type.
 */
return new class extends Migration
{
    private const VERSION = '2027';

    /**
     * @return array<int, array<string, mixed>>
     */
    private function objectives(): array
    {
        return [
            ['code' => 'alt_power_equipment', 'name' => 'Station Equipment on Alternative Power', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Run all station equipment, including laptops, networking and accessories, on alternative power for the whole operating period.'],
            ['code' => 'alt_power_full', 'name' => 'Operate 100% on Alternative Power', 'objective_multiplier' => 2, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Run everything, including lights, HVAC and other appliances, on alternative power for the whole operating period. Also earns the station-equipment objective.'],
            ['code' => 'away_from_home', 'name' => 'Operate Away From Home', 'objective_multiplier' => 3, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Set up the field station more than half a mile from home.'],
            ['code' => 'multiple_antennas', 'name' => 'Deploy Multiple Antennas', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Deploy two or more not-previously-installed antennas and make at least one contact on each.'],
            ['code' => 'sstv_image', 'name' => 'Send and Receive the WFD SSTV Image', 'objective_multiplier' => 2, 'trigger_type' => 'manual', 'requires_proof' => true, 'description' => 'Send and receive the official WFD SSTV image and the WFD exchange in a logged QSO.'],
            ['code' => 'crossband_repeater', 'name' => 'Cross-Band Repeater Contacts', 'objective_multiplier' => 3, 'trigger_type' => 'manual', 'requires_proof' => true, 'description' => 'Set up a cross-band repeater during the setup period and make three contacts through it. Do not log these contacts.'],
            ['code' => 'winlink_email', 'name' => 'Send and Receive a Winlink Email', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => true, 'description' => 'Send and receive at least one email from a winlink.org address over amateur RF within the operational period.'],
            ['code' => 'bulletin', 'name' => 'Copy the WFD Bulletin', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => true, 'description' => 'Accurately copy the WFD Bulletin and submit the copy with the log.'],
            ['code' => 'six_bands', 'name' => 'Three Contacts on Six Bands', 'objective_multiplier' => 6, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Log at least three contacts on each of six or more different bands. Satellite contacts do not count.'],
            ['code' => 'twelve_bands', 'name' => 'Three Contacts on Twelve Bands', 'objective_multiplier' => 6, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Log at least three contacts on each of twelve or more different bands. The six-band objective counts toward this one.'],
            ['code' => 'multiple_modes', 'name' => 'Use Multiple Modes', 'objective_multiplier' => 2, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Complete a QSO in at least two of the three mode groups (CW, Phone, Digital). Using all three earns no more.'],
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

        foreach ($this->objectives() as $objective) {
            $exists = DB::table('bonus_types')
                ->where('event_type_id', $eventTypeId)
                ->where('rules_version', self::VERSION)
                ->where('code', $objective['code'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('bonus_types')->insert([
                'event_type_id' => $eventTypeId,
                'rules_version' => self::VERSION,
                'code' => $objective['code'],
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
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
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
