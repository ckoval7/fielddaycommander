<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Seeders;

use App\Models\BonusType;
use App\Models\EventType;
use Illuminate\Database\Seeder;

class BonusTypeSeeder extends Seeder
{
    /** @var array<string, string> Maps bonus codes to non-manual trigger types. */
    private const TRIGGER_TYPES = [
        'sm_sec_message' => 'derived',
        'nts_message' => 'derived',
        'w1aw_bulletin' => 'derived',
        'elected_official_visit' => 'derived',
        'agency_visit' => 'derived',
        'youth_participation' => 'hybrid',
        'emergency_power' => 'derived',
        'satellite_qso' => 'derived',
        'safety_officer' => 'derived',
        'site_responsibilities' => 'derived',
    ];

    /**
     * Rules versions that inherit verbatim from a parent version, per event type.
     *
     * Each entry ensures the child version has a full bonus-type row set
     * copied from its parent. When a sanctioning body publishes version-specific
     * tweaks, drop the entry and seed the child explicitly (or ship a migration).
     *
     * Keyed by event type code because inheritance is not universal: Winter
     * Field Day 2026 defines its own objectives rather than carrying the 2025
     * rows forward.
     *
     * @var array<string, array<string, string>>
     */
    private const INHERITED_VERSIONS = [
        'FD' => ['2026' => '2025'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fdEventType = EventType::where('code', 'FD')->first();
        $wfdEventType = EventType::where('code', 'WFD')->first();

        $bonuses = array_merge(
            $this->fieldDayBonuses($fdEventType->id),
            $this->winterFieldDayBonuses($wfdEventType->id),
            $this->winterFieldDayObjectives($wfdEventType->id),
            $this->winterFieldDay2027Objectives($wfdEventType->id),
        );

        $bonuses = $this->withInheritedVersions($bonuses, [
            'FD' => $fdEventType->id,
            'WFD' => $wfdEventType->id,
        ]);

        // Seeder is idempotent and non-destructive — existing rows are never overwritten.
        // Use a migration to change values for an already-shipped rules_version.
        foreach ($bonuses as $bonus) {
            $bonus['trigger_type'] ??= self::TRIGGER_TYPES[$bonus['code']] ?? 'manual';

            BonusType::firstOrCreate(
                [
                    'event_type_id' => $bonus['event_type_id'],
                    'rules_version' => $bonus['rules_version'],
                    'code' => $bonus['code'],
                ],
                $bonus
            );
        }
    }

    /**
     * Clone bonus rows for each version listed in INHERITED_VERSIONS so
     * year-over-year rulesets that inherit verbatim still have their own
     * per-version rows for lookup and rescore flows.
     *
     * @param  array<int, array<string, mixed>>  $bonuses
     * @param  array<string, int>  $eventTypeIds
     * @return array<int, array<string, mixed>>
     */
    private function withInheritedVersions(array $bonuses, array $eventTypeIds): array
    {
        foreach (self::INHERITED_VERSIONS as $typeCode => $versions) {
            $eventTypeId = $eventTypeIds[$typeCode] ?? null;

            if ($eventTypeId === null) {
                continue;
            }

            foreach ($versions as $child => $parent) {
                foreach ($bonuses as $bonus) {
                    if ($bonus['rules_version'] !== $parent || $bonus['event_type_id'] !== $eventTypeId) {
                        continue;
                    }

                    $bonus['rules_version'] = $child;
                    $bonuses[] = $bonus;
                }
            }
        }

        return $bonuses;
    }

    /**
     * Get Field Day bonus type definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fieldDayBonuses(int $eventTypeId): array
    {
        return [
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'emergency_power',
                'name' => 'Emergency Power',
                'description' => '100% emergency power for entire operation',
                'base_points' => 100,
                'is_per_transmitter' => true,
                'max_points' => 2000,
                'max_occurrences' => null,
                'requires_proof' => false,
                'eligible_classes' => (['A', 'B', 'C', 'E', 'F']),
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'media_publicity',
                'name' => 'Media Publicity',
                'description' => 'Publicity received from local media',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => true,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'public_location',
                'name' => 'Public Location',
                'description' => 'Set up in public place, not member residence',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => (['A', 'B', 'F']),
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'public_info_booth',
                'name' => 'Information Booth',
                'description' => 'Set up information table for non-hams',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => (['A', 'B', 'F']),
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'nts_message',
                'name' => 'NTS Messages Handled',
                'description' => 'Formal NTS messages originated, relayed, or received (10 points each)',
                'base_points' => 10,
                'is_per_transmitter' => false,
                'is_per_occurrence' => true,
                'max_points' => 100,
                'max_occurrences' => 10,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'social_media',
                'name' => 'Social Media',
                'description' => 'Make FD operation known to general public via social media',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => true,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'safety_officer',
                'name' => 'Safety Officer',
                'description' => 'Designated safety officer for Field Day operation',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => (['A']),
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'natural_power',
                'name' => 'Natural Power QSOs',
                'description' => '5 or more QSOs using 100% natural power (solar, wind, water)',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => (['A', 'B', 'E', 'F']),
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'elected_official_visit',
                'name' => 'Elected Official Visit',
                'description' => 'Site visit by an elected government official as result of invitation',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'agency_visit',
                'name' => 'Served Agency Visit',
                'description' => 'Site visit by representative of an agency served by ARES (Red Cross, Salvation Army, local EM, etc.)',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'satellite_qso',
                'name' => 'Satellite QSO',
                'description' => 'Complete at least one QSO via amateur radio satellite',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => (['A', 'B', 'F']),
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'sm_sec_message',
                'name' => 'Section Manager Message',
                'description' => 'Formal message to ARRL Section Manager or Section Emergency Coordinator',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'w1aw_bulletin',
                'name' => 'W1AW Field Day Bulletin',
                'description' => 'Copy of W1AW Field Day bulletin received via amateur radio',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'educational_activity',
                'name' => 'Educational Activity',
                'description' => 'Formal educational or outreach activity conducted during Field Day',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => (['A', 'F', 'D', 'E']),
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'web_submission',
                'name' => 'Web Submission',
                'description' => 'Submit Field Day log via ARRL web submission',
                'base_points' => 50,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 50,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'youth_participation',
                'name' => 'Youth Participation',
                'description' => 'Participation by licensed operators age 18 or younger (20 points each)',
                'base_points' => 20,
                'is_per_transmitter' => false,
                'is_per_occurrence' => true,
                'max_points' => 100,
                'max_occurrences' => 5,
                'requires_proof' => false,
                'eligible_classes' => (['A', 'B', 'C', 'D', 'E', 'F']),
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'site_responsibilities',
                'name' => 'Site Responsibilities',
                'description' => 'Operator assumes all site responsibilities per ARRL rules',
                'base_points' => 50,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 50,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => (['B', 'C', 'D', 'E', 'F']),
            ],
        ];
    }

    /**
     * Get Winter Field Day bonus type definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function winterFieldDayBonuses(int $eventTypeId): array
    {
        return [
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'alternative_power',
                'name' => 'Alternative Power',
                'description' => 'Use alternative power source for entire operation',
                'base_points' => 500,
                'is_per_transmitter' => false,
                'max_points' => 500,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'away_from_home',
                'name' => 'Away From Home',
                'description' => 'Operate from location other than home',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => (['I', 'O', 'M']),
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2025',
                'code' => 'public_location_wfd',
                'name' => 'Public Location',
                'description' => 'Operate from publicly accessible location',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => (['I', 'O']),
            ],
        ];
    }

    /**
     * Winter Field Day 2026 objectives.
     *
     * Objectives are multipliers rather than point awards: each carries an
     * Objective Multiplier, they are summed, and the score is
     * `QSO points x (OM + 1)`. Source: WFDA SOP (2026).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function winterFieldDayObjectives(int $eventTypeId): array
    {
        return [
            ...$this->winterFieldDayDeploymentObjectives($eventTypeId),
            ...$this->winterFieldDayDigitalObjectives($eventTypeId),
            ...$this->winterFieldDayOperatingObjectives($eventTypeId),
        ];
    }

    /**
     * Deployment and power objectives: how and where the station is set up.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function winterFieldDayDeploymentObjectives(int $eventTypeId): array
    {
        return [
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'alternative_power',
                'name' => 'Operate 100% on Alternative Power',
                'description' => 'Operate exclusively on power not connected to the commercial grid. Lights and HVAC are exempt.',
                'trigger_type' => 'derived',
                'base_points' => 0,
                'objective_multiplier' => 1,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'away_from_home',
                'name' => 'Operate Away From Home',
                'description' => 'Set up the field station more than half a mile from home.',
                'trigger_type' => 'manual',
                'base_points' => 0,
                'objective_multiplier' => 3,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'multiple_antennas',
                'name' => 'Deploy Multiple Antennas',
                'description' => 'Deploy two or more not-previously-installed antennas and make at least one contact on each.',
                'trigger_type' => 'manual',
                'base_points' => 0,
                'objective_multiplier' => 1,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
        ];
    }

    /**
     * Satellite, Winlink and bulletin objectives: contacts made outside plain HF phone/CW.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function winterFieldDayDigitalObjectives(int $eventTypeId): array
    {
        return [
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'fm_satellite',
                'name' => 'FM Satellite Contact',
                'description' => 'Make at least one FM satellite contact during the operating period.',
                'trigger_type' => 'manual',
                'base_points' => 0,
                'objective_multiplier' => 2,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'ssb_cw_satellite',
                'name' => 'SSB or CW Satellite Contact',
                'description' => 'Make at least one satellite contact using SSB or CW.',
                'trigger_type' => 'manual',
                'base_points' => 0,
                'objective_multiplier' => 3,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'winlink_email',
                'name' => 'Send and Receive a Winlink Email',
                'description' => 'Send and receive at least one email via a winlink.org address over amateur RF, timestamped within the operational period.',
                'trigger_type' => 'manual',
                'base_points' => 0,
                'objective_multiplier' => 1,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => true,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'special_bulletin',
                'name' => 'Copy the WFD Special Bulletin',
                'description' => 'Accurately copy the WFD Special Bulletin and submit the copy with the log.',
                'trigger_type' => 'derived',
                'base_points' => 0,
                'objective_multiplier' => 1,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => true,
                'eligible_classes' => null,
            ],
        ];
    }

    /**
     * Operating-achievement objectives: band, mode, power and endurance milestones.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function winterFieldDayOperatingObjectives(int $eventTypeId): array
    {
        return [
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'six_bands',
                'name' => 'Three Contacts on Six Bands',
                'description' => 'Log at least three contacts on each of six or more different bands.',
                'trigger_type' => 'derived',
                'base_points' => 0,
                'objective_multiplier' => 6,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'twelve_bands',
                'name' => 'Three Contacts on Twelve Bands',
                'description' => 'Log at least three contacts on each of twelve or more different bands. The six-band objective counts toward this one.',
                'trigger_type' => 'derived',
                'base_points' => 0,
                'objective_multiplier' => 6,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'multiple_modes',
                'name' => 'Use Multiple Modes',
                'description' => 'Work at least two of the three mode groups (CW, Phone, Digital). Using all three earns no more.',
                'trigger_type' => 'derived',
                'base_points' => 0,
                'objective_multiplier' => 2,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'qrp',
                'name' => 'Operate the Event QRP',
                'description' => 'Every station runs 10 W or less on Phone, or 5 W or less on CW and Digital, for the whole time operated.',
                'trigger_type' => 'derived',
                'base_points' => 0,
                'objective_multiplier' => 4,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $eventTypeId,
                'rules_version' => '2026',
                'code' => 'six_continuous_hours',
                'name' => 'Operate Six Continuous Hours',
                'description' => 'Staff the radio for six continuous hours, monitoring and ready to respond.',
                'trigger_type' => 'manual',
                'base_points' => 0,
                'objective_multiplier' => 2,
                'is_per_transmitter' => false,
                'is_per_occurrence' => false,
                'max_points' => 0,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
        ];
    }

    /**
     * Winter Field Day 2027 objectives.
     *
     * Same multiplier model as 2026. The satellite objectives are gone,
     * alternative power is split into a station-equipment objective and a 100%
     * objective that also earns it, and SSTV image and cross-band repeater
     * objectives are new. Source: WFDA "Winter Field Day 2027" rules, Version 3,
     * 04 OCT 2026. Mirrored by the `seed_winter_field_day_2027_objectives`
     * migration, since production updates do not run seeders.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function winterFieldDay2027Objectives(int $eventTypeId): array
    {
        /** @var array<int, array{code: string, name: string, objective_multiplier: int, trigger_type: string, requires_proof: bool, description: string}> $objectives */
        $objectives = [
            ['code' => 'alt_power_equipment', 'name' => 'Station Equipment on Alternative Power', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Run all station equipment, including laptops, networking and accessories, on alternative power for the whole operating period.'],
            ['code' => 'alt_power_full', 'name' => 'Operate 100% on Alternative Power', 'objective_multiplier' => 2, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Run everything, including lights, HVAC and other appliances, on alternative power for the whole operating period. Also earns the station-equipment objective.'],
            ['code' => 'away_from_home', 'name' => 'Operate Away From Home', 'objective_multiplier' => 3, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Set up the field station more than half a mile from home.'],
            ['code' => 'multiple_antennas', 'name' => 'Deploy Multiple Antennas', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Deploy two or more not-previously-installed antennas and make at least one contact on each.'],
            ['code' => 'sstv_image', 'name' => 'Send and Receive the WFD SSTV Image', 'objective_multiplier' => 2, 'trigger_type' => 'manual', 'requires_proof' => true, 'description' => 'Send and receive the official WFD SSTV image and the WFD exchange in a logged QSO.'],
            ['code' => 'crossband_repeater', 'name' => 'Cross-Band Repeater Contacts', 'objective_multiplier' => 3, 'trigger_type' => 'manual', 'requires_proof' => true, 'description' => 'Set up a cross-band repeater during the setup period and make three contacts through it. Do not log these contacts.'],
            ['code' => 'winlink_email', 'name' => 'Send and Receive a Winlink Email', 'objective_multiplier' => 1, 'trigger_type' => 'manual', 'requires_proof' => true, 'description' => 'Send and receive at least one email from a winlink.org address over amateur RF within the operational period.'],
            ['code' => 'bulletin', 'name' => 'Copy the WFD Bulletin', 'objective_multiplier' => 1, 'trigger_type' => 'derived', 'requires_proof' => true, 'description' => 'Accurately copy the WFD Bulletin and submit the copy with the log.'],
            ['code' => 'six_bands', 'name' => 'Three Contacts on Six Bands', 'objective_multiplier' => 6, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Log at least three contacts on each of six or more different bands. Satellite contacts do not count.'],
            ['code' => 'twelve_bands', 'name' => 'Three Contacts on Twelve Bands', 'objective_multiplier' => 6, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Log at least three contacts on each of twelve or more different bands. The six-band objective counts toward this one.'],
            ['code' => 'multiple_modes', 'name' => 'Use Multiple Modes', 'objective_multiplier' => 2, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Complete a QSO in at least two of the three mode groups (CW, Phone, Digital). Using all three earns no more.'],
            ['code' => 'qrp', 'name' => 'Operate the Event QRP', 'objective_multiplier' => 4, 'trigger_type' => 'derived', 'requires_proof' => false, 'description' => 'Every station runs 10 W or less on Phone, or 5 W or less on CW and Digital, for the whole time operated.'],
            ['code' => 'six_continuous_hours', 'name' => 'Operate Six Continuous Hours', 'objective_multiplier' => 2, 'trigger_type' => 'manual', 'requires_proof' => false, 'description' => 'Staff the radio for six continuous hours, monitoring and ready to respond.'],
        ];

        return array_map(fn (array $objective): array => [
            'event_type_id' => $eventTypeId,
            'rules_version' => '2027',
            ...$objective,
            'base_points' => 0,
            'is_per_transmitter' => false,
            'is_per_occurrence' => false,
            'max_points' => 0,
            'max_occurrences' => 1,
            'eligible_classes' => null,
        ], $objectives);
    }
}
