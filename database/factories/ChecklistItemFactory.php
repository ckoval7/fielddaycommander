<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Enums\ChecklistAnswerType;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistItem>
 */
class ChecklistItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_id' => Checklist::factory(),
            'label' => fake()->sentence(),
            'help_text' => null,
            'answer_type' => ChecklistAnswerType::Checkbox,
            'is_required' => false,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function required(): static
    {
        return $this->state(fn () => ['is_required' => true]);
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function answerType(ChecklistAnswerType $type): static
    {
        return $this->state(fn () => ['answer_type' => $type]);
    }
}
