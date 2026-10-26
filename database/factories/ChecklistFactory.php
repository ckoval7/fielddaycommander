<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Enums\ChecklistCompletionRule;
use App\Enums\ChecklistScope;
use App\Models\Checklist;
use App\Models\EventConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Checklist>
 */
class ChecklistFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_configuration_id' => EventConfiguration::factory(),
            'name' => fake()->words(3, true),
            'description' => null,
            'scope' => ChecklistScope::Event,
            'completion_rule' => ChecklistCompletionRule::RequiredItems,
            'bonus_code' => null,
            'template_key' => null,
            'sort_order' => 0,
        ];
    }

    public function perStation(): static
    {
        return $this->state(fn () => ['scope' => ChecklistScope::Station]);
    }

    public function perPerson(): static
    {
        return $this->state(fn () => ['scope' => ChecklistScope::Person]);
    }

    public function perPeriod(): static
    {
        return $this->state(fn () => ['scope' => ChecklistScope::Period]);
    }

    public function safetyOfficer(): static
    {
        return $this->state(fn () => [
            'name' => 'Safety Officer',
            'bonus_code' => 'safety_officer',
            'completion_rule' => ChecklistCompletionRule::AllItems,
            'template_key' => 'arrl_safety_officer',
        ]);
    }

    public function siteResponsibilities(): static
    {
        return $this->state(fn () => [
            'name' => 'Site Responsibilities',
            'bonus_code' => 'site_responsibilities',
            'completion_rule' => ChecklistCompletionRule::RequiredAndMajority,
            'template_key' => 'arrl_site_responsibilities',
        ]);
    }
}
