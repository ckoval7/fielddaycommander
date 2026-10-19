<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Seeders;

use App\Models\EventType;
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
                'description' => 'Winter Field Day Association event - held the 4th full weekend in January',
                'is_active' => true,
                'setup_offset_hours' => 24,
            ],
            [
                'code' => 'PS',
                'name' => 'Public Service / Other',
                'description' => 'Public service, EmComm drills, nets and other events that are not scored as a contest',
                'is_active' => true,
                'is_scored' => false,
                'setup_offset_hours' => null,
            ],
        ];

        foreach ($eventTypes as $eventType) {
            EventType::firstOrCreate(['code' => $eventType['code']], $eventType);
        }
    }
}
