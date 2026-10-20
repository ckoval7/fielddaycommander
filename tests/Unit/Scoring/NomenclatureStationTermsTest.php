<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Scoring\Dto\Nomenclature;
use App\Scoring\Rules\FieldDay2026;
use App\Scoring\Rules\PublicService;
use App\Scoring\Rules\WinterFieldDay2027;

uses()->group('unit', 'scoring');

test('contests call operating positions stations', function (string $ruleSet) {
    $terms = app($ruleSet)->nomenclature();

    expect($terms->stationSingular)->toBe('Station')
        ->and($terms->stationPlural)->toBe('Stations');
})->with([FieldDay2026::class, WinterFieldDay2027::class]);

test('public service events call them positions', function () {
    $terms = (new PublicService)->nomenclature();

    expect($terms->stationSingular)->toBe('Position')
        ->and($terms->stationPlural)->toBe('Positions');
});

test('the default wording is stations', function () {
    expect((new Nomenclature)->stationPlural)->toBe('Stations');
});
