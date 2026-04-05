<?php

namespace Database\Factories;

use App\Enums\ChecklistType;
use App\Models\EventConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SafetyChecklistItem>
 */
class SafetyChecklistItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'checklist_type' => fake()->randomElement(ChecklistType::cases()),
            'label' => fake()->sentence(),
            'help_text' => null,
            'is_required' => false,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => true,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    public function safetyOfficer(): static
    {
        return $this->state(fn (array $attributes) => [
            'checklist_type' => ChecklistType::SafetyOfficer,
        ]);
    }

    public function siteResponsibilities(): static
    {
        return $this->state(fn (array $attributes) => [
            'checklist_type' => ChecklistType::SiteResponsibilities,
        ]);
    }
}
