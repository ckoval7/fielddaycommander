<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\EventType;
use App\Models\OperatingClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperatingClass>
 */
class OperatingClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventType = EventType::where('code', 'FD')->first();
        if (! $eventType) {
            $eventType = EventType::create([
                'name' => 'Field Day',
                'code' => 'FD',
                'description' => 'ARRL Field Day',
                'is_active' => true,
            ]);
        }

        return [
            'event_type_id' => $eventType->id,
            'code' => '1A',
            'name' => 'Class 1A',
            'description' => 'Test Operating Class',
            'allows_gota' => true,
            'allows_free_vhf' => false,
            'requires_emergency_power' => false,
        ];
    }
}
