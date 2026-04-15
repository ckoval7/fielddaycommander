<?php

use App\Support\WmoCode;

test('icon returns o-sun for clear sky', function () {
    expect(WmoCode::icon(0))->toBe('o-sun');
});

test('icon returns o-sun for mainly clear', function () {
    expect(WmoCode::icon(1))->toBe('o-sun');
});

test('icon returns o-cloud for partly cloudy', function () {
    expect(WmoCode::icon(2))->toBe('o-cloud');
});

test('icon returns o-cloud-arrow-down for moderate rain', function () {
    expect(WmoCode::icon(63))->toBe('o-cloud-arrow-down');
});

test('icon returns o-bolt for thunderstorm', function () {
    expect(WmoCode::icon(95))->toBe('o-bolt');
});

test('icon returns o-bolt for thunderstorm with hail', function () {
    expect(WmoCode::icon(96))->toBe('o-bolt');
});

test('icon returns o-cloud for unmapped code', function () {
    expect(WmoCode::icon(999))->toBe('o-cloud');
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
