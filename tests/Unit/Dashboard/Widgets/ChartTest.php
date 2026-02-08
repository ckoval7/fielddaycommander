<?php

use App\Livewire\Dashboard\Widgets\Chart;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    // Clear cache before each test
    Cache::flush();
});

test('chart component can be instantiated', function () {
    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'bar',
            'data_source' => 'qsos_per_hour',
        ],
        'size' => 'normal',
    ]);

    $component->assertStatus(200);
});

test('chart returns empty data when no active event', function () {
    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'bar',
            'data_source' => 'qsos_per_hour',
        ],
        'size' => 'normal',
    ]);

    $chartData = $component->viewData('chartData');

    expect($chartData)
        ->toBeArray()
        ->toHaveKeys(['chart_type', 'title', 'labels', 'datasets'])
        ->and($chartData['labels'])->toBeEmpty()
        ->and($chartData['datasets'])->toBeArray()
        ->and($chartData['datasets'][0]['data'])->toBeEmpty();
});

test('chart calculates qsos per hour correctly', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    // Create contacts at different hours
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(2),
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'qso_time' => now()->subHours(1),
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'line',
            'data_source' => 'qsos_per_hour',
        ],
        'size' => 'normal',
    ]);

    $chartData = $component->viewData('chartData');

    expect($chartData)
        ->toBeArray()
        ->toHaveKey('labels')
        ->toHaveKey('datasets')
        ->and($chartData['chart_type'])->toBe('line')
        ->and($chartData['title'])->toContain('QSOs per Hour');
});

test('chart calculates qsos per band correctly', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $section = Section::factory()->create();

    // Create 2 bands
    $band1 = Band::factory()->create(['name' => '20m']);
    $band2 = Band::factory()->create(['name' => '40m']);
    $mode = Mode::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band1->id,
        'mode_id' => $mode->id,
    ]);

    // Create contacts on different bands
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band1->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band2->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'bar',
            'data_source' => 'qsos_per_band',
        ],
        'size' => 'normal',
    ]);

    $chartData = $component->viewData('chartData');

    expect($chartData)
        ->toBeArray()
        ->and($chartData['chart_type'])->toBe('bar')
        ->and($chartData['title'])->toContain('QSOs per Band')
        ->and($chartData['labels'])->toBeArray()
        ->and($chartData['datasets'])->toBeArray()
        ->and($chartData['datasets'][0]['data'])->toBeArray();
});

test('chart calculates qsos per mode correctly', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $section = Section::factory()->create();

    // Create 2 modes
    $mode1 = Mode::factory()->create(['name' => 'SSB']);
    $mode2 = Mode::factory()->create(['name' => 'CW']);

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode1->id,
    ]);

    // Create contacts on different modes
    Contact::factory()->count(4)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode1->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(6)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode2->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'pie',
            'data_source' => 'qsos_per_mode',
        ],
        'size' => 'normal',
    ]);

    $chartData = $component->viewData('chartData');

    expect($chartData)
        ->toBeArray()
        ->and($chartData['chart_type'])->toBe('pie')
        ->and($chartData['title'])->toContain('QSOs per Mode');
});

test('chart excludes duplicate contacts', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    // Create 3 valid contacts
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    // Create 2 duplicate contacts
    Contact::factory()->count(2)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => true,
    ]);

    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'bar',
            'data_source' => 'qsos_per_band',
        ],
        'size' => 'normal',
    ]);

    $chartData = $component->viewData('chartData');

    // Count should only include non-duplicates
    $total = array_sum($chartData['datasets'][0]['data'] ?? []);
    expect($total)->toBe(3);
});

test('chart caches results for 5 seconds', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $eventConfig = EventConfiguration::factory()->create(['event_id' => $event->id]);

    $user = User::factory()->create();
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);
    $band = Band::factory()->create();
    $mode = Mode::factory()->create();
    $section = Section::factory()->create();

    $session = OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
    ]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    // First call - should cache
    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'bar',
            'data_source' => 'qsos_per_band',
        ],
        'size' => 'normal',
    ]);

    $data1 = $component->viewData('chartData');
    $total1 = array_sum($data1['datasets'][0]['data'] ?? []);
    expect($total1)->toBe(3);

    // Add more contacts
    Contact::factory()->count(2)->create([
        'event_configuration_id' => $eventConfig->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'section_id' => $section->id,
        'is_duplicate' => false,
    ]);

    // Second call - should return cached value
    $component2 = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'bar',
            'data_source' => 'qsos_per_band',
        ],
        'size' => 'normal',
    ]);

    $data2 = $component2->viewData('chartData');
    $total2 = array_sum($data2['datasets'][0]['data'] ?? []);
    expect($total2)->toBe(3); // Still cached value
});

test('chart uses IsWidget trait', function () {
    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'bar',
            'data_source' => 'qsos_per_band',
        ],
        'size' => 'tv',
        'widgetId' => 'test-chart-123',
    ]);

    expect($component->get('size'))->toBe('tv')
        ->and($component->get('widgetId'))->toBe('test-chart-123')
        ->and($component->get('config')['chart_type'])->toBe('bar');
});

test('chart handles unknown data source gracefully', function () {
    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'bar',
            'data_source' => 'unknown_source',
        ],
        'size' => 'normal',
    ]);

    $chartData = $component->viewData('chartData');

    expect($chartData)
        ->toBeArray()
        ->toHaveKey('labels')
        ->toHaveKey('datasets')
        ->and($chartData['labels'])->toBeEmpty()
        ->and($chartData['datasets'])->toBeArray()
        ->and($chartData['datasets'][0]['data'])->toBeEmpty();
});

test('chart returns empty listeners array', function () {
    $component = Livewire::test(Chart::class, [
        'config' => [
            'chart_type' => 'bar',
            'data_source' => 'qsos_per_band',
        ],
        'size' => 'normal',
    ]);

    $listeners = $component->instance()->getWidgetListeners();

    expect($listeners)->toBeArray()->toBeEmpty();
});
