<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Try to get Field Day event type, or create one if it doesn't exist
        $eventType = \App\Models\EventType::where('code', 'FD')->first();
        if (! $eventType) {
            $eventType = \App\Models\EventType::create([
                'name' => 'Field Day',
                'code' => 'FD',
                'description' => 'ARRL Field Day',
            ]);
        }

        return [
            'name' => 'Field Day '.now()->year,
            'event_type_id' => $eventType->id,
            'year' => now()->year,
            'start_time' => now()->addDays(30)->setTime(18, 0, 0),
            'end_time' => now()->addDays(31)->setTime(20, 59, 0),
            'setup_allowed_from' => now()->addDays(29)->setTime(18, 0, 0),
            'max_setup_hours' => 24,
            'is_active' => true,
            'is_current' => false,
        ];
    }
}
