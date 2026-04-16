<?php

use App\Support\WmoCode;

test('icon returns phosphor sun for clear sky', function () {
    expect(WmoCode::icon(0))->toBe('phosphor-sun-duotone');
});

test('icon returns phosphor sun dim for mainly clear', function () {
    expect(WmoCode::icon(1))->toBe('phosphor-sun-dim-duotone');
});

test('icon returns phosphor cloud sun for partly cloudy', function () {
    expect(WmoCode::icon(2))->toBe('phosphor-cloud-sun-duotone');
});

test('icon returns phosphor cloud rain for moderate rain', function () {
    expect(WmoCode::icon(63))->toBe('phosphor-cloud-rain-duotone');
});

test('icon returns phosphor cloud lightning for thunderstorm', function () {
    expect(WmoCode::icon(95))->toBe('phosphor-cloud-lightning-duotone');
});

test('icon returns phosphor cloud lightning for thunderstorm with hail', function () {
    expect(WmoCode::icon(96))->toBe('phosphor-cloud-lightning-duotone');
});

test('icon returns phosphor cloud for unmapped code', function () {
    expect(WmoCode::icon(999))->toBe('phosphor-cloud-duotone');
});

test('label returns Clear for code 0', function () {
    expect(WmoCode::label(0))->toBe('Clear');
});

test('label returns correct label for moderate rain', function () {
    expect(WmoCode::label(63))->toBe('Moderate Rain');
});

test('label returns correct label for thunderstorm', function () {
    expect(WmoCode::label(95))->toBe('Thunderstorm');
});

test('label returns correct label for thunderstorm with hail', function () {
    expect(WmoCode::label(96))->toBe('Thunderstorm with Hail');
});

test('label returns Unknown for unmapped code', function () {
    expect(WmoCode::label(999))->toBe('Unknown');
});
