<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventBonus>
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
        $eventType = \App\Models\EventType::where('code', 'FD')->first();
        if (! $eventType) {
            $eventType = \App\Models\EventType::create([
                'name' => 'Field Day',
                'code' => 'FD',
                'description' => 'ARRL Field Day',
            ]);
        }

        $bonusType = \App\Models\BonusType::first();
        if (! $bonusType) {
            $bonusType = \App\Models\BonusType::create([
                'event_type_id' => $eventType->id,
                'code' => 'TEST_BONUS',
                'name' => 'Test Bonus',
                'description' => 'A test bonus type',
                'base_points' => 100,
            ]);
        }

        return [
            'event_configuration_id' => \App\Models\EventConfiguration::factory(),
            'bonus_type_id' => $bonusType->id,
            'claimed_by_user_id' => \App\Models\User::factory(),
            'quantity' => 1,
            'calculated_points' => fake()->numberBetween(10, 500),
            'notes' => null,
            'proof_file_path' => null,
            'is_verified' => false,
            'verified_by_user_id' => null,
            'verified_at' => null,
        ];
    }
}
