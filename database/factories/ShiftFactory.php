<?php

namespace Database\Factories;

use App\Models\EventConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventConfiguration = EventConfiguration::factory()->create();
        $startTime = fake()->dateTimeBetween('+1 hour', '+24 hours');
        $endTime = (clone $startTime)->modify('+2 hours');

        return [
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => \App\Models\ShiftRole::factory()->state([
                'event_configuration_id' => $eventConfiguration->id,
            ]),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'capacity' => 1,
            'is_open' => false,
            'notes' => null,
        ];
    }

    /**
     * Indicate this shift is open for self-signup.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_open' => true,
        ]);
    }

    /**
     * Set a specific capacity for the shift.
     */
    public function withCapacity(int $capacity): static
    {
        return $this->state(fn (array $attributes) => [
            'capacity' => $capacity,
        ]);
    }

    /**
     * Associate the shift with a specific event configuration.
     */
    public function forEventConfiguration(EventConfiguration $eventConfiguration): static
    {
        return $this->state(fn (array $attributes) => [
            'event_configuration_id' => $eventConfiguration->id,
            'shift_role_id' => \App\Models\ShiftRole::factory()->state([
                'event_configuration_id' => $eventConfiguration->id,
            ]),
        ]);
    }
}
