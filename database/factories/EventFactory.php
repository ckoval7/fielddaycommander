<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
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
        $eventType = EventType::where('code', 'FD')->first();
        if (! $eventType) {
            $eventType = EventType::create([
                'name' => 'Field Day',
                'code' => 'FD',
                'description' => 'ARRL Field Day',
            ]);
        }

        $year = $this->faker->unique()->numberBetween(2000, 2099);

        return [
            'name' => 'Field Day '.$year,
            'event_type_id' => $eventType->id,
            'year' => $year,
            'start_time' => now()->addDays(30)->setTime(18, 0, 0),
            'end_time' => now()->addDays(31)->setTime(20, 59, 0),
            'setup_allowed_from' => now()->addDays(29)->setTime(18, 0, 0),
            'max_setup_hours' => 24,
            'is_active' => true,
            'is_current' => false,
        ];
    }

    /**
     * Configure the model factory.
     *
     * Defaults rules_version to the event's year when not explicitly provided.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Event $event) {
            if ($event->rules_version === null) {
                $event->rules_version = (string) $event->year;
            }
        });
    }
}
