<?php

namespace Database\Factories;

use App\Models\EventType;
use App\Models\Mode;
use App\Models\ModeRulePoints;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModeRulePoints>
 */
class ModeRulePointsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_type_id' => EventType::factory(),
            'rules_version' => '2026',
            'mode_id' => Mode::factory(),
            'points' => 2,
        ];
    }
}
