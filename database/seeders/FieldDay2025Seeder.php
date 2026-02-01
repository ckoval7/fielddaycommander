<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class FieldDay2025Seeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $fdEventType = \App\Models\EventType::where('code', 'FD')->first();

        \App\Models\Event::create([
            'event_type_id' => $fdEventType->id,
            'name' => 'Field Day 2025',
            'year' => 2025,
            'start_time' => '2025-06-28 18:00:00',
            'end_time' => '2025-06-29 18:00:00',
            'setup_allowed_from' => '2025-06-27 18:00:00',
            'max_setup_hours' => 24,
            'is_active' => true,
            'is_current' => true,
        ]);
    }
}
