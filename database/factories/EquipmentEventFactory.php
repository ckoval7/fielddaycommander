<?php

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\Event;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EquipmentEvent>
 */
class EquipmentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'equipment_id' => Equipment::factory(),
            'event_id' => Event::factory(),
            'station_id' => fake()->optional()->randomElement(Station::pluck('id')->toArray()),
            'status' => 'committed',
            'committed_at' => now(),
            'expected_delivery_at' => fake()->optional()->dateTimeBetween('now', '+7 days'),
            'delivery_notes' => fake()->optional()->sentence(),
            'manager_notes' => fake()->optional()->sentence(),
            'status_changed_at' => now(),
        ];
    }

    /**
     * Set the equipment event status to delivered.
     */
    public function delivered(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'delivered',
            ];
        });
    }

    /**
     * Set the equipment event status to in_use.
     */
    public function inUse(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'in_use',
            ];
        });
    }

    /**
     * Set the equipment event status to returned.
     */
    public function returned(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'returned',
            ];
        });
    }

    /**
     * Set the equipment event status to cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'cancelled',
            ];
        });
    }

    /**
     * Set the equipment event status to lost.
     */
    public function lost(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'lost',
            ];
        });
    }

    /**
     * Set the equipment event status to damaged.
     */
    public function damaged(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => 'damaged',
            ];
        });
    }
}
