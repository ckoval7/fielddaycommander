<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class OperatingClassSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fdEventType = \App\Models\EventType::where('code', 'FD')->first();
        $wfdEventType = \App\Models\EventType::where('code', 'WFD')->first();

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
                'description' => 'Operation from home QTH',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
            [
                'event_type_id' => $wfdEventType->id,
                'code' => 'I',
                'name' => 'Indoor',
                'description' => 'Operation from indoor location (not home)',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
            [
                'event_type_id' => $wfdEventType->id,
                'code' => 'O',
                'name' => 'Outdoor',
                'description' => 'Operation from outdoor location',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
            [
                'event_type_id' => $wfdEventType->id,
                'code' => 'M',
                'name' => 'Mobile',
                'description' => 'Mobile operation',
                'allows_gota' => false,
                'allows_free_vhf' => false,
                'requires_emergency_power' => false,
            ],
        ];

        foreach (array_merge($fdClasses, $wfdClasses) as $class) {
            \App\Models\OperatingClass::create($class);
        }
    }
}
