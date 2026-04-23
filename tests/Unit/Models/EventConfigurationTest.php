<?php

use App\Enums\PowerSource;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\Station;

uses()->group('unit', 'models');

test('calculates 5x power multiplier for QRP with natural power', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('5');
});

test('calculates 5x power multiplier for QRP with solar power', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_solar' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('5');
});

test('calculates 2x power multiplier for QRP with commercial power', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_commercial_power' => true,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('2');
});

test('calculates 2x power multiplier for 6-100W regardless of power source', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 100,
        'uses_battery' => true,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('2');
});

test('calculates 1x power multiplier for over 100W', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 150,
        'uses_battery' => true,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('1');
});

test('station with higher power overrides event config for multiplier', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 100,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('2')
        ->and($config->effectiveMaxPowerWatts())->toBe(100);
});

test('station with higher power over 100W gives 1x multiplier', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 150,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('1')
        ->and($config->effectiveMaxPowerWatts())->toBe(150);
});

test('station at or below event config power does not affect multiplier', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 5,
        'power_source' => PowerSource::Battery,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('5')
        ->and($config->effectiveMaxPowerWatts())->toBe(5);
});

test('highest station power among multiple stations determines multiplier', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 5,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 100,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('2')
        ->and($config->effectiveMaxPowerWatts())->toBe(100);
});

test('event config with no stations uses event power for multiplier', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('5')
        ->and($config->effectiveMaxPowerWatts())->toBe(5);
});

test('hasContacts returns true when configuration has contacts', function () {
    $config = EventConfiguration::factory()->create();

    // Create a contact for this configuration
    Contact::factory()->create([
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

    Contact::factory()->create([
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

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
    ]);

    expect($config->isLocked())->toBeTrue();
});

test('isLocked returns true when event has started', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHour(),
    ]);

    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
    ]);

    expect($config->isLocked())->toBeTrue();
});

test('isLocked returns false when no contacts and event not started', function () {
    $event = Event::factory()->create([
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
    Contact::factory(3)->create([
        'event_configuration_id' => $config->id,
        'points' => 1,
        'is_duplicate' => false,
    ]);

    // Create 1 duplicate (should not count)
    Contact::factory()->create([
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
    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'calculated_points' => 50,
        'is_verified' => true,
    ]);

    // Create unverified bonus (should not count)
    EventBonus::factory()->create([
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
    Contact::factory(2)->create([
        'event_configuration_id' => $config->id,
        'points' => 1,
        'is_duplicate' => false,
    ]);

    // Create verified bonus: 100 points
    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    // 4 QSO points + 100 bonus = 104
    expect($config->calculateFinalScore())->toBe(104);
});

test('calculateQsoScore excludes GOTA contacts', function () {
    $config = EventConfiguration::factory()->create(['max_power_watts' => 100]);
    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'is_gota_contact' => false,
        'points' => 2,
        'is_duplicate' => false,
    ]);
    Contact::factory()->gota()->create([
        'event_configuration_id' => $config->id,
        'is_duplicate' => false,
    ]);
    expect($config->calculateQsoScore())->toBe(4); // 2 points x 2 multiplier
});

test('calculateGotaBonus returns 5 points per non-duplicate GOTA contact', function () {
    $config = EventConfiguration::factory()->create(['has_gota_station' => true]);
    Contact::factory()->gota()->count(3)->create([
        'event_configuration_id' => $config->id,
        'is_duplicate' => false,
    ]);
    Contact::factory()->gota()->create([
        'event_configuration_id' => $config->id,
        'is_duplicate' => true,
    ]);
    expect($config->calculateGotaBonus())->toBe(15);
});

test('calculateGotaCoachBonus returns 100 when 10+ supervised contacts exist', function () {
    $config = EventConfiguration::factory()->create(['has_gota_station' => true]);
    $station = Station::factory()->gota()->create(['event_configuration_id' => $config->id]);
    $session = OperatingSession::factory()->supervised()->create(['station_id' => $station->id]);
    Contact::factory()->gota()->count(10)->create([
        'event_configuration_id' => $config->id,
        'operating_session_id' => $session->id,
        'is_duplicate' => false,
    ]);
    expect($config->calculateGotaCoachBonus())->toBe(100);
});

test('calculateGotaCoachBonus returns 0 when fewer than 10 supervised contacts', function () {
    $config = EventConfiguration::factory()->create(['has_gota_station' => true]);
    $station = Station::factory()->gota()->create(['event_configuration_id' => $config->id]);
    $session = OperatingSession::factory()->supervised()->create(['station_id' => $station->id]);
    Contact::factory()->gota()->count(9)->create([
        'event_configuration_id' => $config->id,
        'operating_session_id' => $session->id,
        'is_duplicate' => false,
    ]);
    expect($config->calculateGotaCoachBonus())->toBe(0);
});

test('emergency power bonus disqualified when any station uses commercial mains', function () {
    $operatingClass = OperatingClass::factory()->create(['code' => 'A']);
    $config = EventConfiguration::factory()->create([
        'uses_commercial_power' => false,
        'transmitter_count' => 2,
        'operating_class_id' => $operatingClass->id,
    ]);

    BonusType::factory()->create([
        'code' => 'emergency_power',
        'base_points' => 100,
        'is_active' => true,
        'eligible_classes' => ['A', 'B', 'C', 'E', 'F'],
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'power_source' => PowerSource::Battery,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'power_source' => PowerSource::CommercialMains,
    ]);

    expect($config->calculateEmergencyPowerBonus())->toBe(0);
});

test('emergency power bonus awarded when all stations use emergency power sources', function () {
    $operatingClass = OperatingClass::factory()->create(['code' => 'A']);
    $event = Event::factory()->create(['year' => 2025, 'rules_version' => '2025']);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'uses_commercial_power' => false,
        'transmitter_count' => 2,
        'operating_class_id' => $operatingClass->id,
    ]);

    BonusType::factory()->create([
        'event_type_id' => $event->event_type_id,
        'rules_version' => '2025',
        'code' => 'emergency_power',
        'base_points' => 100,
        'is_active' => true,
        'eligible_classes' => ['A', 'B', 'C', 'E', 'F'],
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'power_source' => PowerSource::Generator,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'power_source' => PowerSource::Battery,
    ]);

    expect($config->calculateEmergencyPowerBonus())->toBe(200);
});

test('natural power bonus lost when any station uses generator', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 5,
        'power_source' => PowerSource::Battery,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 5,
        'power_source' => PowerSource::Generator,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('2');
});

test('natural power bonus retained when all stations use natural power', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_solar' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 5,
        'power_source' => PowerSource::Solar,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 5,
        'power_source' => PowerSource::Other,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('5');
});

test('natural power bonus lost when any station uses commercial mains', function () {
    $config = EventConfiguration::factory()->create([
        'max_power_watts' => 5,
        'uses_battery' => true,
        'uses_commercial_power' => false,
        'uses_generator' => false,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 5,
        'power_source' => PowerSource::Battery,
    ]);

    Station::factory()->create([
        'event_configuration_id' => $config->id,
        'max_power_watts' => 5,
        'power_source' => PowerSource::CommercialMains,
    ]);

    expect($config->calculatePowerMultiplier())->toBe('2');
});
