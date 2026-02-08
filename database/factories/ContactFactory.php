<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contact>
 */
class ContactFactory extends Factory
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

        // Get a random active section if available
        $section = \App\Models\Section::where('is_active', true)->inRandomOrder()->first();

        return [
            'event_configuration_id' => \App\Models\EventConfiguration::factory(),
            'operating_session_id' => \App\Models\OperatingSession::factory(),
            'logger_user_id' => \App\Models\User::factory(),
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'qso_time' => appNow(),
            'callsign' => strtoupper(fake()->bothify('??#???')),
            'section_id' => $section?->id,
            'received_exchange' => fake()->word(),
            'power_watts' => fake()->numberBetween(5, 100),
            'is_gota_contact' => false,
            'is_natural_power' => false,
            'is_satellite' => false,
            'points' => 1,
            'is_duplicate' => false,
            'notes' => null,
        ];
    }

    public function duplicate(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_duplicate' => true,
            'points' => 0,
        ]);
    }

    public function withSection(string $code = 'CT'): static
    {
        return $this->state(function (array $attributes) use ($code) {
            $section = \App\Models\Section::where('code', $code)->first();

            return [
                'section_id' => $section?->id,
            ];
        });
    }
}
