<?php

use App\Models\BonusType;
use App\Models\EventType;
use App\Scoring\Dto\PowerContext;
use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\FieldDayTest;

uses()->group('unit', 'scoring');

beforeEach(function () {
    $this->fd = EventType::firstOrCreate(['code' => 'FD'], ['name' => 'Field Day']);
    $this->rules = new FieldDayTest;
});

test('identifies itself as the synthetic test ruleset', function () {
    expect($this->rules->id())->toBe('FD-TEST')
        ->and($this->rules->version())->toBe('TEST')
        ->and($this->rules->eventTypeCode())->toBe('FD');
});

test('power multiplier: 6-100W returns 3 (overrides 2025 default of 2)', function () {
    $ctx = new PowerContext(effectivePowerWatts: 50, qualifiesForQrpNaturalBonus: true);

    expect($this->rules->powerMultiplier($ctx))->toBe('3');
});

test('power multiplier: >100W still returns 1', function () {
    $ctx = new PowerContext(effectivePowerWatts: 150, qualifiesForQrpNaturalBonus: true);

    expect($this->rules->powerMultiplier($ctx))->toBe('1');
});

test('power multiplier: QRP + natural still returns 5', function () {
    $ctx = new PowerContext(effectivePowerWatts: 5, qualifiesForQrpNaturalBonus: true);

    expect($this->rules->powerMultiplier($ctx))->toBe('5');
});

test('power multiplier: QRP without natural bonus uses the bumped 3', function () {
    $ctx = new PowerContext(effectivePowerWatts: 5, qualifiesForQrpNaturalBonus: false);

    expect($this->rules->powerMultiplier($ctx))->toBe('3');
});

test('bonus() resolves use_fd_commander against rules_version=TEST', function () {
    BonusType::factory()->create([
        'event_type_id' => $this->fd->id,
        'rules_version' => 'TEST',
        'code' => 'use_fd_commander',
        'base_points' => 100,
    ]);

    $bonus = $this->rules->bonus('use_fd_commander');

    expect($bonus)->not->toBeNull()
        ->and($bonus->base_points)->toBe(100)
        ->and($bonus->rules_version)->toBe('TEST');
});

test('inherits all non-overridden 2025 behaviour', function () {
    $base = new FieldDay2025;

    expect($this->rules->gotaPointsPerContact())->toBe($base->gotaPointsPerContact())
        ->and($this->rules->gotaCoachThreshold())->toBe($base->gotaCoachThreshold())
        ->and($this->rules->gotaCoachBonus())->toBe($base->gotaCoachBonus())
        ->and($this->rules->youthMaxCount())->toBe($base->youthMaxCount())
        ->and($this->rules->youthPointsPerYouth())->toBe($base->youthPointsPerYouth())
        ->and($this->rules->emergencyPowerMaxTransmitters())->toBe($base->emergencyPowerMaxTransmitters());
});
