<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OperatingSession>
 */
class OperatingSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Ensure we have required reference data
        $band = \App\Models\Band::first();
        if (! $band) {
            $band = \App\Models\Band::create([
                'name' => '20m',
                'meters' => 20,
                'frequency_mhz' => 14.175,
            ]);
        }

        $mode = \App\Models\Mode::first();
        if (! $mode) {
            $mode = \App\Models\Mode::create([
                'name' => 'SSB',
                'category' => 'Phone',
            ]);
        }

        return [
            'station_id' => \App\Models\Station::factory(),
            'operator_user_id' => \App\Models\User::factory(),
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'start_time' => now(),
            'end_time' => null,
            'power_watts' => 100,
            'qso_count' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => now(),
            'end_time' => null,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => now()->subHours(2),
            'end_time' => now()->subHour(),
        ]);
    }

    public function supervised(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_supervised' => true,
        ]);
    }
}
