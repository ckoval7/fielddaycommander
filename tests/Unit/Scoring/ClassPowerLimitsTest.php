<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Scoring\ClassPowerLimits;
use App\Scoring\Contracts\LimitsPowerByClass;
use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\WinterFieldDay2027;
use App\Scoring\RuleSetFactory;

dataset('field day versions', ['2025', '2026', 'TEST']);

dataset('winter field day versions', ['2026', '2027']);

test('ARRL Field Day limits Classes A, B and C to 500 W and leaves D, E and F unlimited', function (string $version) {
    $rules = app(RuleSetFactory::class)->resolve('FD', $version);

    $limits = collect(['A', 'B', 'C', 'D', 'E', 'F'])
        ->mapWithKeys(fn (string $code): array => [$code => ClassPowerLimits::for($rules, $code)])
        ->all();

    expect($rules->version())->toBe($version)
        ->and($limits)->toBe(['A' => 500, 'B' => 500, 'C' => 500, 'D' => null, 'E' => null, 'F' => null]);
})->with('field day versions');

test('Winter Field Day limits every class to 100 W', function (string $version) {
    $rules = app(RuleSetFactory::class)->resolve('WFD', $version);

    $limits = collect(['H', 'I', 'O', 'M'])
        ->mapWithKeys(fn (string $code): array => [$code => ClassPowerLimits::for($rules, $code)])
        ->all();

    expect($rules->version())->toBe($version)
        ->and($limits)->toBe(['H' => 100, 'I' => 100, 'O' => 100, 'M' => 100]);
})->with('winter field day versions');

test('a rulebook that states its own class limits overrides its family defaults', function () {
    $fieldDay = new class extends FieldDay2025 implements LimitsPowerByClass
    {
        public function maxPowerWattsForClass(string $classCode): ?int
        {
            return $classCode === 'D' ? 1000 : null;
        }
    };

    $winter = new class extends WinterFieldDay2027 implements LimitsPowerByClass
    {
        public function maxPowerWattsForClass(string $classCode): ?int
        {
            return 150;
        }
    };

    expect(ClassPowerLimits::for($fieldDay, 'A'))->toBeNull()
        ->and(ClassPowerLimits::for($fieldDay, 'D'))->toBe(1000)
        ->and(ClassPowerLimits::for($winter, 'H'))->toBe(150);
});
