<?php

namespace Database\Factories;

use App\Models\EventConfiguration;
use App\Models\User;
use App\Models\W1awBulletin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<W1awBulletin> */
class W1awBulletinFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'user_id' => User::factory(),
            'frequency' => fake()->randomElement(['7.0475', '14.0475', '18.0975', '3.5815']),
            'mode' => fake()->randomElement(['cw', 'digital', 'phone']),
            'bulletin_text' => 'ARRL FIELD DAY MESSAGE '.strtoupper(fake()->sentence(20)),
            'received_at' => now(),
        ];
    }
}
