<?php

namespace Database\Factories;

use App\Models\SafetyChecklistEntry;
use App\Models\SafetyChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SafetyChecklistEntry>
 */
class SafetyChecklistEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'safety_checklist_item_id' => SafetyChecklistItem::factory(),
            'is_completed' => false,
            'completed_by_user_id' => null,
            'completed_at' => null,
            'notes' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_completed' => true,
            'completed_by_user_id' => User::factory(),
            'completed_at' => now(),
        ]);
    }
}
