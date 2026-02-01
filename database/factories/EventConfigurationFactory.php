<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventConfiguration>
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
        $section = \App\Models\Section::where('code', 'CT')->first();
        if (! $section) {
            $section = \App\Models\Section::create([
                'code' => 'CT',
                'name' => 'Connecticut',
                'region' => 'W1',
            ]);
        }

        $eventType = \App\Models\EventType::where('code', 'FD')->first();
        if (! $eventType) {
            $eventType = \App\Models\EventType::create([
                'name' => 'Field Day',
                'code' => 'FD',
                'description' => 'ARRL Field Day',
            ]);
        }

        $operatingClass = \App\Models\OperatingClass::where('code', '1A')->first();
        if (! $operatingClass) {
            $operatingClass = \App\Models\OperatingClass::create([
                'event_type_id' => $eventType->id,
                'code' => '1A',
                'name' => 'Class 1A',
                'description' => 'Test Class',
            ]);
        }

        return [
            'event_id' => \App\Models\Event::factory(),
            'created_by_user_id' => \App\Models\User::factory(),
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
        ];
    }
}
