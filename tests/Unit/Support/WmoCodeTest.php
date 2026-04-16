<?php

use App\Support\WmoCode;

test('returns duotone clear-sky icons with day/night variants', function () {
    expect(WmoCode::icon(0))->toBe('phosphor-sun-duotone');
    expect(WmoCode::icon(0, isNight: true))->toBe('phosphor-moon-duotone');

    expect(WmoCode::icon(1))->toBe('phosphor-sun-dim-duotone');
    expect(WmoCode::icon(1, isNight: true))->toBe('phosphor-moon-stars-duotone');
});

test('returns partly-cloudy with day/night variants', function () {
    expect(WmoCode::icon(2))->toBe('phosphor-cloud-sun-duotone');
    expect(WmoCode::icon(2, isNight: true))->toBe('phosphor-cloud-moon-duotone');
});

test('overcast and fog have no night variant', function () {
    expect(WmoCode::icon(3))->toBe('phosphor-cloud-duotone');
    expect(WmoCode::icon(3, isNight: true))->toBe('phosphor-cloud-duotone');
    expect(WmoCode::icon(45))->toBe('phosphor-cloud-fog-duotone');
    expect(WmoCode::icon(48, isNight: true))->toBe('phosphor-cloud-fog-duotone');
});

test('drizzle codes map to drop icons', function () {
    expect(WmoCode::icon(51))->toBe('phosphor-drop-half-duotone');
    expect(WmoCode::icon(53))->toBe('phosphor-drop-duotone');
    expect(WmoCode::icon(55))->toBe('phosphor-drop-simple-duotone');
});

test('rain and showers map to cloud-rain', function () {
    foreach ([61, 63, 65, 80, 81, 82] as $code) {
        expect(WmoCode::icon($code))->toBe('phosphor-cloud-rain-duotone');
    }
});

test('snow codes map to cloud-snow, snow grains to snowflake', function () {
    foreach ([71, 73, 75, 85, 86] as $code) {
        expect(WmoCode::icon($code))->toBe('phosphor-cloud-snow-duotone');
    }
    expect(WmoCode::icon(77))->toBe('phosphor-snowflake-duotone');
});

test('thunderstorm codes map to cloud-lightning', function () {
    foreach ([95, 96, 99] as $code) {
        expect(WmoCode::icon($code))->toBe('phosphor-cloud-lightning-duotone');
    }
});

test('unknown codes fall back to cloud', function () {
    expect(WmoCode::icon(9999))->toBe('phosphor-cloud-duotone');
    expect(WmoCode::icon(-1, isNight: true))->toBe('phosphor-cloud-duotone');
});

test('labels and colors still work as before', function () {
    expect(WmoCode::label(0))->toBe('Clear');
    expect(WmoCode::label(95))->toBe('Thunderstorm');
    expect(WmoCode::color(0))->toContain('text-amber-500');
});
