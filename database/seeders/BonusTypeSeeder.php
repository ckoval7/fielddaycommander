<?php

namespace Database\Seeders;

use App\Models\BonusType;
use App\Models\EventType;
use Illuminate\Database\Seeder;

class BonusTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fdEventType = EventType::where('code', 'FD')->first();
        $wfdEventType = EventType::where('code', 'WFD')->first();

        $bonuses = array_merge(
            $this->fieldDayBonuses($fdEventType->id),
            $this->winterFieldDayBonuses($wfdEventType->id)
        );

        // Seeder is idempotent and non-destructive — existing rows are never overwritten.
        // Use a migration to change values for an already-shipped rules_version.
        foreach ($bonuses as $bonus) {
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
                'description' => 'Official visit by broadcast or print media representative',
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
}
