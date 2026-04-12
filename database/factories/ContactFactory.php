<?php

namespace Database\Factories;

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
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

        // Get a random active section if available
        $section = Section::where('is_active', true)->inRandomOrder()->first();

        $callsign = strtoupper(fake()->bothify('??#???'));
        $fdClass = fake()->numberBetween(1, 5).fake()->randomElement(['A', 'B', 'C', 'D', 'E', 'F']);

        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'operating_session_id' => OperatingSession::factory(),
            'logger_user_id' => User::factory(),
            'band_id' => $band->id,
            'mode_id' => $mode->id,
            'qso_time' => appNow(),
            'callsign' => $callsign,
            'section_id' => $section?->id,
            'exchange_class' => $fdClass,
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

    public function gota(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_gota_contact' => true,
            'points' => 0,
            'gota_operator_first_name' => fake()->firstName(),
            'gota_operator_last_name' => fake()->lastName(),
            'gota_operator_callsign' => strtoupper(fake()->bothify('??#???')),
        ]);
    }

    public function withSection(string $code = 'CT'): static
    {
        return $this->state(function (array $attributes) use ($code) {
            $section = Section::where('code', $code)->first();

            return [
                'section_id' => $section?->id,
            ];
        });
    }
}
