<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Seeders;

use App\Models\EventType;
use App\Models\OperatingClass;
use Illuminate\Database\Seeder;

class OperatingClassSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fdEventType = EventType::where('code', 'FD')->first();
        $wfdEventType = EventType::where('code', 'WFD')->first();

        $fdClasses = [
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'A',
                'name' => 'Class A',
                'description' => 'Portable emergency power, not at home',
                'allows_gota' => true,
                'allows_free_vhf' => true,
                'requires_emergency_power' => true,
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'B',
                'name' => 'Class B',
                'description' => 'Home stations using commercial power',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'C',
                'name' => 'Class C',
                'description' => 'Mobile stations',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'D',
                'name' => 'Class D',
                'description' => 'Home stations using emergency power',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => true,
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'E',
                'name' => 'Class E',
                'description' => 'Home stations using any power source',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
            [
                'event_type_id' => $fdEventType->id,
                'code' => 'F',
                'name' => 'Class F',
                'description' => 'Emergency Operations Center',
                'allows_gota' => true,
                'allows_free_vhf' => true,
                'requires_emergency_power' => true,
            ],
        ];

        $wfdClasses = [
            [
                'event_type_id' => $wfdEventType->id,
                'code' => 'H',
                'name' => 'Home',
                'description' => 'Home station: operating inside a permanent livable residence',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
            [
                'event_type_id' => $wfdEventType->id,
                'code' => 'I',
                'name' => 'Indoor',
                'description' => 'Indoor station: away from home, inside an insulated, weather-protected building on a permanent foundation',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
            [
                'event_type_id' => $wfdEventType->id,
                'code' => 'O',
                'name' => 'Outdoor',
                'description' => 'Outdoor station: partly or fully exposed shelter without plumbing, HVAC or running water (tents, pavilions, canopies)',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
            [
                'event_type_id' => $wfdEventType->id,
                'code' => 'M',
                'name' => 'Mobile',
                'description' => 'Mobile station: operating from a mobile or potentially mobile structure such as an RV, car, van or trailer',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
        ];

        foreach (array_merge($fdClasses, $wfdClasses) as $class) {
            OperatingClass::firstOrCreate(
                ['event_type_id' => $class['event_type_id'], 'code' => $class['code']],
                $class,
            );
        }
    }
}
