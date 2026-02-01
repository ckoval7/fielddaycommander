<?php

use App\Models\EventConfiguration;

uses()->group('unit', 'models');

test('calculates 5x power multiplier for QRP with natural power', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    expect($config->calculatePowerMultiplier())->toBe(5);
});

test('calculates 5x power multiplier for QRP with solar power', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_solar' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    expect($config->calculatePowerMultiplier())->toBe(5);
});

test('calculates 2x power multiplier for QRP with commercial power', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_commercial_power' => true,
    ]);

    expect($config->calculatePowerMultiplier())->toBe(2);
});

test('calculates 2x power multiplier for 6-100W regardless of power source', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 100,
        'uses_battery' => true,
    ]);

    expect($config->calculatePowerMultiplier())->toBe(2);
});

test('calculates 1x power multiplier for over 100W', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 150,
        'uses_battery' => true,
    ]);

    expect($config->calculatePowerMultiplier())->toBe(1);
});

test('hasContacts returns true when configuration has contacts', function () {
    $config = EventConfiguration::factory()->create();

    // Create a contact for this configuration
    \App\Models\Contact::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    expect($config->hasContacts())->toBeTrue();
});

test('hasContacts returns false when configuration has no contacts', function () {
    $config = EventConfiguration::factory()->create();

    expect($config->hasContacts())->toBeFalse();
});

test('hasGotaContacts returns true when configuration has GOTA contacts', function () {
    $config = EventConfiguration::factory()->create([
        'has_gota_station' => true,
    ]);

    \App\Models\Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'is_gota_contact' => true,
    ]);

    expect($config->hasGotaContacts())->toBeTrue();
});

test('hasGotaContacts returns false when configuration has no GOTA contacts', function () {
    $config = EventConfiguration::factory()->create();

    expect($config->hasGotaContacts())->toBeFalse();
});

test('isLocked returns true when configuration has contacts', function () {
    $config = EventConfiguration::factory()->create();

    \App\Models\Contact::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    expect($config->isLocked())->toBeTrue();
});

test('isLocked returns true when event has started', function () {
    $event = \App\Models\Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHour(),
    ]);

    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
    ]);

    expect($config->isLocked())->toBeTrue();
});

test('isLocked returns false when no contacts and event not started', function () {
    $event = \App\Models\Event::factory()->create([
        'start_time' => now()->addDay(),
        'end_time' => now()->addDays(2),
    ]);

    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
    ]);

    expect($config->isLocked())->toBeFalse();
});

test('calculateQsoScore returns total QSO points with power multiplier', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    // Create 3 contacts worth 1 point each = 3 points
    \App\Models\Contact::factory(3)->create([
        'event_configuration_id' => $config->id,
        'points' => 1,
        'is_duplicate' => false,
    ]);

    // Create 1 duplicate (should not count)
    \App\Models\Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'points' => 1,
        'is_duplicate' => true,
    ]);

    // 3 contacts × 1 point × 5 multiplier = 15
    expect($config->calculateQsoScore())->toBe(15);
});

test('calculateBonusScore returns sum of verified bonus points', function () {
    $config = EventConfiguration::factory()->create();

    // Create verified bonuses
    \App\Models\EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    \App\Models\EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'calculated_points' => 50,
        'is_verified' => true,
    ]);

    // Create unverified bonus (should not count)
    \App\Models\EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'calculated_points' => 200,
        'is_verified' => false,
    ]);

    expect($config->calculateBonusScore())->toBe(150);
});

test('calculateFinalScore returns QSO score plus bonus score', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 100,
        'uses_battery' => true,
    ]);

    // Create contacts: 2 × 1 × 2 multiplier = 4 points
    \App\Models\Contact::factory(2)->create([
        'event_configuration_id' => $config->id,
        'points' => 1,
        'is_duplicate' => false,
    ]);

    // Create verified bonus: 100 points
    \App\Models\EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    // 4 QSO points + 100 bonus = 104
    expect($config->calculateFinalScore())->toBe(104);
});
