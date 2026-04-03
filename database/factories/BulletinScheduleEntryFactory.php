<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BulletinScheduleEntry> */
class BulletinScheduleEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'scheduled_at' => now()->addHours(fake()->numberBetween(1, 48)),
            'mode' => fake()->randomElement(['cw', 'digital', 'phone']),
            'frequencies' => fake()->randomElement([
                '3.5815, 7.0475, 14.0475',
                '3.5975, 7.095, 14.095',
                '1.855, 3.990, 7.290, 14.290',
            ]),
            'source' => fake()->randomElement(['W1AW', 'K6KPH']),
            'created_by' => User::factory(),
        ];
    }

    public function upcoming(): static
    {
        return $this->state(fn () => ['scheduled_at' => now()->addMinutes(10)]);
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'scheduled_at' => now()->subHours(2),
        ]);
    }
}
