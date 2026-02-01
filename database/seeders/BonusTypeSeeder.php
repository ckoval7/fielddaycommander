<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class BonusTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fdEventType = \App\Models\EventType::where('code', 'FD')->first();
        $wfdEventType = \App\Models\EventType::where('code', 'WFD')->first();

        $fdBonuses = [
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'emergency_power',
                'name' => 'Emergency Power',
                'description' => '100% emergency power for entire operation',
                'base_points' => 100,
                'is_per_transmitter' => true,
                'max_points' => 2000,
                'max_occurrences' => null,
                'requires_proof' => false,
                'eligible_classes' => json_encode(['A', 'D', 'E', 'F']),
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'media_publicity',
                'name' => 'Media Publicity',
                'description' => 'Official visit by broadcast or print media representative',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => true,
                'max_points' => null,
                'max_occurrences' => null,
                'requires_proof' => true,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'public_location',
                'name' => 'Public Location',
                'description' => 'Set up in public place, not member residence',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => json_encode(['A', 'F']),
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'public_info_booth',
                'name' => 'Information Booth',
                'description' => 'Set up information table for non-hams',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $fdEventType->id,
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
                'event_type_id' => $fdEventType->id,
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
                'event_type_id' => $fdEventType->id,
                'code' => 'safety_officer',
                'name' => 'Safety Officer',
                'description' => 'Designated safety officer for Field Day operation',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => json_encode(['A', 'F']),
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'natural_power',
                'name' => 'Natural Power QSOs',
                'description' => '5 or more QSOs using 100% natural power (solar, wind, water)',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'site_visit',
                'name' => 'Agency/Official Visit',
                'description' => 'Visit by served agency representative or elected official',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'is_per_occurrence' => true,
                'max_points' => null,
                'max_occurrences' => null,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'satellite_qso',
                'name' => 'Satellite QSO',
                'description' => 'Complete at least one QSO via amateur radio satellite',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => null,
            ],
        ];

        $wfdBonuses = [
            [
                'event_type_id' => $wfdEventType->id,
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
                'event_type_id' => $wfdEventType->id,
                'code' => 'away_from_home',
                'name' => 'Away From Home',
                'description' => 'Operate from location other than home',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => json_encode(['I', 'O', 'M']),
            ],
            [
                'event_type_id' => $wfdEventType->id,
                'code' => 'public_location_wfd',
                'name' => 'Public Location',
                'description' => 'Operate from publicly accessible location',
                'base_points' => 100,
                'is_per_transmitter' => false,
                'max_points' => 100,
                'max_occurrences' => 1,
                'requires_proof' => false,
                'eligible_classes' => json_encode(['I', 'O']),
            ],
        ];

        foreach (array_merge($fdBonuses, $wfdBonuses) as $bonus) {
            \App\Models\BonusType::create($bonus);
        }
    }
}
