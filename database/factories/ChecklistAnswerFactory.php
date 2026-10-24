<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\ChecklistAnswer;
use App\Models\ChecklistItem;
use App\Models\ChecklistRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistAnswer>
 */
class ChecklistAnswerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_run_id' => ChecklistRun::factory(),
            'checklist_item_id' => ChecklistItem::factory(),
            'value' => '1',
            'notes' => null,
            'answered_by_user_id' => null,
            'answered_at' => now(),
        ];
    }
}
