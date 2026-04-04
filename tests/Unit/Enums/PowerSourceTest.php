<?php

use App\Enums\PowerSource;

uses()->group('unit', 'enums');

test('all power sources have labels', function () {
    foreach (PowerSource::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

test('commercial mains is not emergency power', function () {
    expect(PowerSource::CommercialMains->isEmergencyPower())->toBeFalse();
});

test('non-commercial sources qualify as emergency power', function () {
    expect(PowerSource::Generator->isEmergencyPower())->toBeTrue();
    expect(PowerSource::Battery->isEmergencyPower())->toBeTrue();
    expect(PowerSource::Solar->isEmergencyPower())->toBeTrue();
    expect(PowerSource::Other->isEmergencyPower())->toBeTrue();
});

test('battery solar and other qualify as natural power', function () {
    expect(PowerSource::Battery->isNaturalPower())->toBeTrue();
    expect(PowerSource::Solar->isNaturalPower())->toBeTrue();
    expect(PowerSource::Other->isNaturalPower())->toBeTrue();
});

test('generator and commercial mains do not qualify as natural power', function () {
    expect(PowerSource::Generator->isNaturalPower())->toBeFalse();
    expect(PowerSource::CommercialMains->isNaturalPower())->toBeFalse();
});
