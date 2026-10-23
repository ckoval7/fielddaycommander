<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Scoring\Dto\PowerContext;
use App\Scoring\PowerMultiplierTiers;
use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\FieldDayTest;

test('it reads the Field Day 2025 tiers from the rulebook', function () {
    expect(PowerMultiplierTiers::for(new FieldDay2025))->toBe([
        ['multiplier' => '5', 'minWatts' => 1, 'maxWatts' => 5, 'naturalPowerOnly' => true],
        ['multiplier' => '2', 'minWatts' => 1, 'maxWatts' => 100, 'naturalPowerOnly' => false],
        ['multiplier' => '1', 'minWatts' => 101, 'maxWatts' => null, 'naturalPowerOnly' => false],
    ]);
});

test('it follows a rulebook with different multipliers', function () {
    expect(array_column(PowerMultiplierTiers::for(new FieldDayTest), 'multiplier'))
        ->toBe(['5', '3', '1']);
});

test('it follows a rulebook with different thresholds', function () {
    $rules = new class extends FieldDay2025
    {
        public function powerMultiplier(PowerContext $ctx): string
        {
            if ($ctx->effectivePowerWatts > 150) {
                return '1';
            }

            return $ctx->effectivePowerWatts <= 10 && $ctx->qualifiesForQrpNaturalBonus ? '5' : '2';
        }
    };

    expect(PowerMultiplierTiers::for($rules))->toBe([
        ['multiplier' => '5', 'minWatts' => 1, 'maxWatts' => 10, 'naturalPowerOnly' => true],
        ['multiplier' => '2', 'minWatts' => 1, 'maxWatts' => 150, 'naturalPowerOnly' => false],
        ['multiplier' => '1', 'minWatts' => 151, 'maxWatts' => null, 'naturalPowerOnly' => false],
    ]);
});
