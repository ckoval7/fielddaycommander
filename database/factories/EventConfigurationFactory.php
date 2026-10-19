<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventConfiguration>
 */
class EventConfigurationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Ensure we have required reference data
        $section = Section::where('code', 'CT')->first();
        if (! $section) {
            $section = Section::create([
                'code' => 'CT',
                'name' => 'Connecticut',
                'region' => 'W1',
            ]);
        }

        $eventType = EventType::where('code', 'FD')->first();
        if (! $eventType) {
            $eventType = EventType::create([
                'name' => 'Field Day',
                'code' => 'FD',
                'description' => 'ARRL Field Day',
            ]);
        }

        // Operating class codes are a single letter; the transmitter count is
        // a separate field, so seeding "1A" here would not match a real class.
        // Define the whole Field Day set so exchanges are validated against a
        // realistic rulebook rather than a single fixture class.
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $code) {
            OperatingClass::firstOrCreate(
                ['event_type_id' => $eventType->id, 'code' => $code],
                ['name' => 'Class '.$code, 'description' => 'Test Class '.$code],
            );
        }

        $operatingClass = OperatingClass::where('event_type_id', $eventType->id)
            ->where('code', 'A')
            ->firstOrFail();

        return [
            'event_id' => Event::factory(),
            'created_by_user_id' => User::factory(),
            'callsign' => 'W1AW',
            'club_name' => 'Test Radio Club',
            'section_id' => $section->id,
            'operating_class_id' => $operatingClass->id,
            'transmitter_count' => 1,
            'has_gota_station' => false,
            'max_power_watts' => 100,
            'power_multiplier' => '2',
            'uses_commercial_power' => true,
            'uses_generator' => false,
            'uses_battery' => false,
            'uses_solar' => false,
            'uses_wind' => false,
            'uses_water' => false,
            'uses_methane' => false,
            'uses_other_power' => null,
            'guestbook_enabled' => false,
        ];
    }

    /**
     * A configuration for an unscored Public Service / Other event.
     */
    public function publicService(): static
    {
        return $this->state(fn () => [
            'event_id' => Event::factory()->publicService(),
        ]);
    }
}
