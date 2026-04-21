<?php

use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\FieldDay2026;

uses()->group('unit', 'scoring');

test('FieldDay2026 identifies itself correctly', function () {
    $rules = new FieldDay2026;

    expect($rules->id())->toBe('FD-2026')
        ->and($rules->version())->toBe('2026')
        ->and($rules->eventTypeCode())->toBe('FD');
});

test('FieldDay2026 inherits all 2025 behavior until ARRL announces changes', function () {
    $r2026 = new FieldDay2026;
    $r2025 = new FieldDay2025;

    expect($r2026->gotaPointsPerContact())->toBe($r2025->gotaPointsPerContact())
        ->and($r2026->gotaCoachThreshold())->toBe($r2025->gotaCoachThreshold())
        ->and($r2026->gotaCoachBonus())->toBe($r2025->gotaCoachBonus())
        ->and($r2026->youthMaxCount())->toBe($r2025->youthMaxCount())
        ->and($r2026->youthPointsPerYouth())->toBe($r2025->youthPointsPerYouth())
        ->and($r2026->emergencyPowerMaxTransmitters())->toBe($r2025->emergencyPowerMaxTransmitters());
});
