<?php

use App\Livewire\Dashboard\Widgets\InfoCard;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
});

/**
 * Pluck the value of the row whose label matches.
 */
function infoRow(array $data, string $label): ?string
{
    foreach ($data['rows'] as $row) {
        if ($row['label'] === $label) {
            return $row['value'];
        }
    }

    return null;
}

// ────────────────────────────────────────────────────────────────
// Component basics
// ────────────────────────────────────────────────────────────────

test('info card component can be instantiated', function () {
    $component = Livewire::test(InfoCard::class, [
        'config' => [],
        'size' => 'normal',
    ]);

    $component->assertStatus(200);
});

test('info card returns empty listeners array', function () {
    $component = Livewire::test(InfoCard::class, [
        'config' => [],
        'size' => 'normal',
    ]);

    expect($component->instance()->getWidgetListeners())->toBeArray()->toBeEmpty();
});

test('info card uses IsWidget trait', function () {
    $component = Livewire::test(InfoCard::class, [
        'config' => [],
        'size' => 'tv',
        'widgetId' => 'test-info-card-123',
    ]);

    expect($component->get('size'))->toBe('tv')
        ->and($component->get('widgetId'))->toBe('test-info-card-123')
        ->and($component->get('config'))->toBe([]);
});

test('info card generates correct cache key', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $component = Livewire::test(InfoCard::class, [
        'config' => [],
        'size' => 'normal',
    ]);

    $cacheKey = $component->instance()->cacheKey();

    expect($cacheKey)->toBeString()
        ->toContain('dashboard:widget:InfoCard')
        ->toContain((string) $event->id);
});

// ────────────────────────────────────────────────────────────────
// Default variant: event_details
// ────────────────────────────────────────────────────────────────

test('event_details variant returns N/A rows when no active event', function () {
    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'event_details'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['title'])->toBe('Event Info')
        ->and(infoRow($data, 'Event'))->toBe('N/A')
        ->and(infoRow($data, 'Location'))->toBe('N/A')
        ->and(infoRow($data, 'Class'))->toBe('N/A')
        ->and(infoRow($data, 'Call Sign'))->toBe('N/A');
});

test('event_details variant returns N/A rows when event has no configuration', function () {
    Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'event_details'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect(infoRow($data, 'Event'))->not->toBe('N/A')
        ->and(infoRow($data, 'Location'))->toBe('N/A')
        ->and(infoRow($data, 'Class'))->toBe('N/A')
        ->and(infoRow($data, 'Call Sign'))->toBe('N/A');
});

test('event_details variant displays event name, callsign, section, class', function () {
    $section = Section::factory()->create([
        'name' => 'Eastern Massachusetts',
        'code' => 'EM',
    ]);

    $eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day']
    );

    $operatingClass = OperatingClass::create([
        'event_type_id' => $eventType->id,
        'name' => 'Class D',
        'code' => 'D',
        'description' => 'Test Class D',
    ]);

    $event = Event::factory()->create([
        'name' => 'Field Day 2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'K1MZ',
        'section_id' => $section->id,
        'operating_class_id' => $operatingClass->id,
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'event_details'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['title'])->toBe('Event Info')
        ->and(infoRow($data, 'Event'))->toBe('Field Day 2025')
        ->and(infoRow($data, 'Location'))->toBe('Eastern Massachusetts')
        ->and(infoRow($data, 'Class'))->toBe('Class D')
        ->and(infoRow($data, 'Call Sign'))->toBe('K1MZ');
});

test('event_details variant marks call sign as highlighted', function () {
    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'event_details'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    $callSignRow = collect($data['rows'])->firstWhere('label', 'Call Sign');

    expect($callSignRow['value'])->toBe('W1AW')
        ->and($callSignRow['highlight'] ?? false)->toBeTrue();
});

test('event_details is the default variant when info_type is missing', function () {
    $event = Event::factory()->create(['name' => 'Some Event']);
    EventConfiguration::factory()->create(['event_id' => $event->id, 'callsign' => 'W1AW']);

    $component = Livewire::test(InfoCard::class, [
        'config' => [],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['title'])->toBe('Event Info')
        ->and(infoRow($data, 'Event'))->toBe('Some Event');
});

// ────────────────────────────────────────────────────────────────
// Location variant
// ────────────────────────────────────────────────────────────────

test('location variant surfaces grid square, coordinates, city/state, talk-in', function () {
    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'grid_square' => 'FN42',
        'latitude' => 42.3601,
        'longitude' => -71.0589,
        'city' => 'Boston',
        'state' => 'MA',
        'talk_in_frequency' => '146.520',
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'location'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['title'])->toBe('Location')
        ->and(infoRow($data, 'City / State'))->toBe('Boston, MA')
        ->and(infoRow($data, 'Grid Square'))->toBe('FN42')
        ->and(infoRow($data, 'Coordinates'))->toBe('42.3601, -71.0589')
        ->and(infoRow($data, 'Talk-in'))->toBe('146.520 MHz');
});

test('location variant shows N/A for missing fields', function () {
    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'grid_square' => null,
        'latitude' => null,
        'longitude' => null,
        'city' => null,
        'state' => null,
        'talk_in_frequency' => null,
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'location'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect(infoRow($data, 'City / State'))->toBe('N/A')
        ->and(infoRow($data, 'Grid Square'))->toBe('N/A')
        ->and(infoRow($data, 'Coordinates'))->toBe('N/A')
        ->and(infoRow($data, 'Talk-in'))->toBe('N/A');
});

test('location variant emphasises grid square', function () {
    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'grid_square' => 'FN42',
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'location'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    $row = collect($data['rows'])->firstWhere('label', 'Grid Square');

    expect($row['highlight'] ?? false)->toBeTrue();
});

// ────────────────────────────────────────────────────────────────
// Operating Class variant
// ────────────────────────────────────────────────────────────────

test('operating_class variant lists class, transmitters, GOTA, max watts, power sources', function () {
    $eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day']
    );

    $operatingClass = OperatingClass::create([
        'event_type_id' => $eventType->id,
        'name' => 'Class A — Club, Portable',
        'code' => '3A',
        'description' => 'Three-transmitter Class A',
    ]);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'operating_class_id' => $operatingClass->id,
        'transmitter_count' => 3,
        'has_gota_station' => true,
        'gota_callsign' => 'W1GOTA',
        'max_power_watts' => 100,
        'uses_battery' => true,
        'uses_solar' => true,
        'uses_generator' => false,
        'uses_commercial_power' => false,
        'uses_wind' => false,
        'uses_water' => false,
        'uses_methane' => false,
        'uses_other_power' => false,
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'operating_class'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect($data['title'])->toBe('Operating Class')
        ->and(infoRow($data, 'Class'))->toBe('3A — Class A — Club, Portable')
        ->and(infoRow($data, 'Transmitters'))->toBe('3')
        ->and(infoRow($data, 'GOTA Station'))->toBe('Yes (W1GOTA)')
        ->and(infoRow($data, 'Max Power'))->toBe('100 W')
        ->and(infoRow($data, 'Power Sources'))->toBe('Battery, Solar');
});

test('operating_class variant reports no GOTA when disabled', function () {
    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'has_gota_station' => false,
        'gota_callsign' => null,
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'operating_class'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect(infoRow($data, 'GOTA Station'))->toBe('No');
});

test('operating_class variant reports None when no power sources are flagged', function () {
    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'uses_battery' => false,
        'uses_solar' => false,
        'uses_generator' => false,
        'uses_commercial_power' => false,
        'uses_wind' => false,
        'uses_water' => false,
        'uses_methane' => false,
        'uses_other_power' => false,
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'operating_class'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    expect(infoRow($data, 'Power Sources'))->toBe('None');
});

test('operating_class variant emphasises class field', function () {
    $event = Event::factory()->create();
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'operating_class'],
        'size' => 'normal',
    ]);

    $data = $component->viewData('data');

    $row = collect($data['rows'])->firstWhere('label', 'Class');

    expect($row['highlight'] ?? false)->toBeTrue();
});

// ────────────────────────────────────────────────────────────────
// Caching + size variants
// ────────────────────────────────────────────────────────────────

test('info card caches results for 60 seconds', function () {
    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $first = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'event_details'],
        'size' => 'normal',
    ]);

    expect(infoRow($first->viewData('data'), 'Call Sign'))->toBe('W1AW');

    $event->eventConfiguration->update(['callsign' => 'W2XYZ']);

    $second = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'event_details'],
        'size' => 'normal',
    ]);

    expect(infoRow($second->viewData('data'), 'Call Sign'))->toBe('W1AW');
});

test('info card renders in tv size variant', function () {
    $event = Event::factory()->create(['name' => 'Field Day 2025']);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'event_details'],
        'size' => 'tv',
    ]);

    $component->assertViewHas('size', 'tv');
    $data = $component->viewData('data');
    expect(infoRow($data, 'Event'))->toBe('Field Day 2025');
});

test('info card renders in normal size variant', function () {
    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $component = Livewire::test(InfoCard::class, [
        'config' => ['info_type' => 'event_details'],
        'size' => 'normal',
    ]);

    $component->assertViewHas('size', 'normal');
    expect(infoRow($component->viewData('data'), 'Call Sign'))->toBe('W1AW');
});
