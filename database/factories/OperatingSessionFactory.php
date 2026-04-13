<?php

namespace Database\Factories;

use App\Models\Band;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperatingSession>
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
        $band = Band::first();
        if (! $band) {
            $band = Band::create([
                'name' => '20m',
                'meters' => 20,
                'frequency_mhz' => 14.175,
            ]);
        }

        $mode = Mode::first();
        if (! $mode) {
            $mode = Mode::create([
                'name' => 'SSB',
                'category' => 'Phone',
            ]);
        }

        return [
            'station_id' => Station::factory(),
            'operator_user_id' => User::factory(),
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

    public function external(string $source = 'N1MM'): static
    {
        return $this->state(fn (array $attributes) => [
            'external_source' => $source,
        ]);
    }
}
