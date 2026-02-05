<?php

namespace Database\Factories;

use App\Models\EventConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'uploaded_by_user_id' => User::factory(),
            'filename' => fake()->word().'.jpg',
            'storage_path' => 'gallery/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => fake()->numberBetween(100000, 5000000),
            'file_hash' => hash('sha256', fake()->uuid()),
            'caption' => fake()->optional()->sentence(),
        ];
    }
}
