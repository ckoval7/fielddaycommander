<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Database\Factories;

use App\Models\BonusType;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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

    /**
     * Configure the model factory.
     *
     * An event configuration holds one row per bonus type (unique index), so
     * when the default bonus type is already claimed for the configuration a
     * fresh bonus type is used instead.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (EventBonus $bonus): void {
            $taken = EventBonus::query()
                ->where('event_configuration_id', $bonus->event_configuration_id)
                ->where('bonus_type_id', $bonus->bonus_type_id)
                ->exists();

            if (! $taken) {
                return;
            }

            $template = BonusType::find($bonus->bonus_type_id);

            $bonus->bonus_type_id = BonusType::create([
                'event_type_id' => $template->event_type_id,
                'code' => 'TEST_BONUS_'.strtoupper(Str::random(8)),
                'name' => 'Test Bonus',
                'description' => 'A test bonus type',
                'base_points' => $template->base_points ?? 100,
            ])->id;
        });
    }
}
