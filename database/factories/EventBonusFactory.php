<?php

namespace Database\Factories;

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventBonus>
 */
class EventBonusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Ensure we have required reference data
        $eventType = EventType::where('code', 'FD')->first();
        if (! $eventType) {
            $eventType = EventType::create([
                'name' => 'Field Day',
                'code' => 'FD',
                'description' => 'ARRL Field Day',
            ]);
        }

        $bonusType = BonusType::first();
        if (! $bonusType) {
            $bonusType = BonusType::create([
                'event_type_id' => $eventType->id,
                'code' => 'TEST_BONUS',
                'name' => 'Test Bonus',
                'description' => 'A test bonus type',
                'base_points' => 100,
            ]);
        }

        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'bonus_type_id' => $bonusType->id,
            'claimed_by_user_id' => User::factory(),
            'quantity' => 1,
            'manual_quantity_adjustment' => null,
            'calculated_points' => fake()->numberBetween(10, 500),
            'notes' => null,
            'proof_file_path' => null,
            'is_verified' => false,
            'verified_by_user_id' => null,
            'verified_at' => null,
        ];
    }
}
