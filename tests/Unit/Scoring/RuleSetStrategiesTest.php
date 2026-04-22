<?php

use App\Scoring\Contracts\BonusStrategy;
use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\FieldDay2026;
use App\Scoring\Rules\FieldDayTest;

it('FieldDay2025 registers all 12 strategies', function () {
    $map = (new FieldDay2025)->strategies();

    $expected = [
        'sm_sec_message',
        'nts_message',
        'w1aw_bulletin',
        'elected_official_visit',
        'agency_visit',
        'media_publicity',
        'youth_participation',
        'social_media',
        'public_location',
        'public_info_booth',
        'educational_activity',
        'web_submission',
    ];

    expect(array_keys($map))->toEqualCanonicalizing($expected);

    foreach ($map as $class) {
        expect(class_exists($class))->toBeTrue()
            ->and(is_subclass_of($class, BonusStrategy::class))->toBeTrue();
    }
});

it('FieldDay2026 inherits FieldDay2025 strategies', function () {
    expect((new FieldDay2026)->strategies())->toBe((new FieldDay2025)->strategies());
});

it('FieldDayTest returns an empty strategies map', function () {
    expect((new FieldDayTest)->strategies())->toBe([]);
});
