<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class EventTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $eventTypes = [
            [
                'code' => 'FD',
                'name' => 'Field Day',
                'description' => 'ARRL Field Day - annual emergency preparedness exercise held the 4th full weekend in June',
                'is_active' => true,
                'setup_offset_hours' => 24,
            ],
            [
                'code' => 'WFD',
                'name' => 'Winter Field Day',
                'description' => 'Winter Field Day Association event - held the last full weekend in January',
                'is_active' => true,
                'setup_offset_hours' => null,
            ],
        ];

        foreach ($eventTypes as $eventType) {
            \App\Models\EventType::create($eventType);
        }
    }
}
