<?php

use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\FieldDay2026;
use App\Scoring\Rules\FieldDayTest;

it('FieldDay2025 returns an empty strategies map for now', function () {
    expect((new FieldDay2025)->strategies())->toBe([]);
});

it('FieldDay2026 inherits FieldDay2025 strategies', function () {
    expect((new FieldDay2026)->strategies())->toBe((new FieldDay2025)->strategies());
});

it('FieldDayTest returns an empty strategies map', function () {
    expect((new FieldDayTest)->strategies())->toBe([]);
});
