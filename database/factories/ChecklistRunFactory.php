<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\Checklist;
use App\Models\ChecklistRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistRun>
 */
class ChecklistRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'checklist_id' => Checklist::factory(),
            'subject_key' => ChecklistRun::EVENT_SUBJECT,
            'station_id' => null,
            'user_id' => null,
            'completed_at' => null,
        ];
    }
}
