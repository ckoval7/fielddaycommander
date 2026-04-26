<?php

use App\Livewire\Dashboard\Widgets\Chart;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Helper: create an active event with event configuration.
 *
 * @return array{event: Event, eventConfig: EventConfiguration}
 */
function createActiveEventWithConfig(): array
{
    EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day']
    );

    $event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $eventConfig = EventConfiguration::factory()->create([
        'event_id' => $event->id,
    ]);

    return ['event' => $event, 'eventConfig' => $eventConfig];
}

/**
 * Helper: create bands with specific names and sort orders.
 *
 * @return array<string, Band>
 */
function createTestBands(): array
{
    $bands = [];
    $bandData = [
        ['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'is_hf' => true, 'sort_order' => 1],
        ['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'is_hf' => true, 'sort_order' => 2],
        ['name' => '15m', 'meters' => 15, 'frequency_mhz' => 21.0, 'is_hf' => true, 'sort_order' => 3],
    ];

    foreach ($bandData as $data) {
        $bands[$data['name']] = Band::firstOrCreate(
            ['name' => $data['name']],
            $data
        );
    }

    return $bands;
}

/**
 * Helper: create modes with specific names.
 *
 * @return array<string, Mode>
 */
function createTestModes(): array
{
    $modes = [];
    $modeData = [
        ['name' => 'SSB', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1],
        ['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2],
        ['name' => 'FT8', 'category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2],
    ];

    foreach ($modeData as $data) {
        $modes[$data['name']] = Mode::firstOrCreate(
            ['name' => $data['name']],
            $data
        );
    }

    return $modes;
}

// ────────────────────────────────────────────────────────────────
// Component Rendering
// ────────────────────────────────────────────────────────────────

test('chart widget renders successfully with default config', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
        'size' => 'normal',
    ])
        ->assertSuccessful();
});

test('chart widget renders successfully with tv size', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
        'size' => 'tv',
    ])
        ->assertSuccessful()
        ->assertSet('size', 'tv');
});

test('chart widget uses IsWidget trait properties', function () {
    $config = ['chart_type' => 'line', 'data_source' => 'qsos_per_band'];

    Livewire::test(Chart::class, [
        'config' => $config,
        'size' => 'normal',
        'widgetId' => 'test-chart-123',
    ])
        ->assertSet('config', $config)
        ->assertSet('size', 'normal')
        ->assertSet('widgetId', 'test-chart-123');
});

// ────────────────────────────────────────────────────────────────
// getData() return structure
// ────────────────────────────────────────────────────────────────

test('getData returns expected structure with no active event', function () {
    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = 'test-chart';

    $data = $chart->getData();

    expect($data)
        ->toBeArray()
        ->toHaveKeys(['labels', 'datasets', 'chart_type', 'title', 'description', 'data_source'])
        ->and($data['labels'])->toBeArray()
        ->and($data['datasets'])->toBeArray()
        ->and($data['chart_type'])->toBe('bar')
        ->and($data['title'])->toBe('QSOs per Hour — Entire Event')
        ->and($data['data_source'])->toBe('qsos_per_hour');
});

test('getData returns empty labels when no active event exists', function () {
    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = 'test-chart';

    $data = $chart->getData();

    expect($data['labels'])->toBeEmpty();
});

test('getData returns correct title for each data source', function (string $dataSource, string $expectedTitle) {
    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => $dataSource];
    $chart->widgetId = "test-chart-{$dataSource}";

    $data = $chart->getData();

    expect($data['title'])->toBe($expectedTitle);
})->with([
    ['qsos_per_hour', 'QSOs per Hour — Entire Event'],
    ['qsos_per_band', 'QSOs per Band — Entire Event'],
    ['qsos_per_mode', 'QSOs per Mode — Entire Event'],
]);

// ────────────────────────────────────────────────────────────────
// Chart type handling
// ────────────────────────────────────────────────────────────────

test('getData returns correct chart_type for each supported type', function (string $chartType) {
    $chart = new Chart;
    $chart->config = ['chart_type' => $chartType, 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = "test-chart-{$chartType}";

    $data = $chart->getData();

    expect($data['chart_type'])->toBe($chartType);
})->with(['bar', 'line', 'pie']);

test('getData defaults to bar for invalid chart type', function () {
    $chart = new Chart;
    $chart->config = ['chart_type' => 'invalid', 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = 'test-chart-invalid';

    $data = $chart->getData();

    expect($data['chart_type'])->toBe('bar');
});

test('getData defaults to qsos_per_hour for invalid data source', function () {
    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'invalid_source'];
    $chart->widgetId = 'test-chart-invalid-source';

    $data = $chart->getData();

    expect($data['data_source'])->toBe('qsos_per_hour')
        ->and($data['title'])->toBe('QSOs per Hour — Entire Event');
});

// ────────────────────────────────────────────────────────────────
// Pie chart dataset formatting
// ────────────────────────────────────────────────────────────────

test('pie chart dataset has individual background colors per slice', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['40m']->id,
        'mode_id' => $modes['CW']->id,
        'is_duplicate' => false,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'pie', 'data_source' => 'qsos_per_band'];
    $chart->widgetId = 'test-pie-chart';

    $data = $chart->getData();

    expect($data['datasets'])->toHaveCount(1)
        ->and($data['datasets'][0]['backgroundColor'])->toBeArray()
        ->and(count($data['datasets'][0]['backgroundColor']))->toBe(count($data['labels']));
});

test('bar chart dataset has single background color', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_band'];
    $chart->widgetId = 'test-bar-chart';

    $data = $chart->getData();

    expect($data['datasets'])->toHaveCount(1)
        ->and($data['datasets'][0]['backgroundColor'])->toBeString();
});

test('line chart dataset has tension and fill properties', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();

    $chart = new Chart;
    $chart->config = ['chart_type' => 'line', 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = 'test-line-chart';

    $data = $chart->getData();

    expect($data['datasets'])->toHaveCount(1)
        ->and($data['datasets'][0])->toHaveKey('tension')
        ->and($data['datasets'][0]['tension'])->toBe(0.3)
        ->and($data['datasets'][0])->toHaveKey('fill')
        ->and($data['datasets'][0]['fill'])->toBeTrue();
});

// ────────────────────────────────────────────────────────────────
// QSOs per Hour data source
// ────────────────────────────────────────────────────────────────

test('qsos_per_hour returns data with contacts', function () {
    ['event' => $event, 'eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'qso_time' => now()->subHours(2),
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['40m']->id,
        'mode_id' => $modes['CW']->id,
        'qso_time' => now()->subHour(),
        'is_duplicate' => false,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = 'test-qph';

    $data = $chart->getData();

    expect($data['labels'])->not->toBeEmpty()
        ->and($data['datasets'][0]['data'])->not->toBeEmpty()
        ->and(array_sum($data['datasets'][0]['data']))->toBe(8);
});

test('qsos_per_hour excludes duplicate contacts', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'qso_time' => now()->subHour(),
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'qso_time' => now()->subHour(),
        'is_duplicate' => true,
        'points' => 0,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = 'test-qph-dupes';

    $data = $chart->getData();

    expect(array_sum($data['datasets'][0]['data']))->toBe(3);
});

test('qsos_per_hour fills in zero-count hours for complete timeline', function () {
    ['event' => $event, 'eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'qso_time' => $event->start_time->copy()->addHours(2),
        'is_duplicate' => false,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = 'test-qph-gaps';

    $data = $chart->getData();

    $zeroHours = array_filter($data['datasets'][0]['data'], fn ($v) => $v === 0);
    expect(count($zeroHours))->toBeGreaterThan(0);
});

// ────────────────────────────────────────────────────────────────
// QSOs per Band data source
// ────────────────────────────────────────────────────────────────

test('qsos_per_band returns correct counts grouped by band', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['40m']->id,
        'mode_id' => $modes['CW']->id,
        'is_duplicate' => false,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_band'];
    $chart->widgetId = 'test-qpb';

    $data = $chart->getData();

    expect($data['labels'])->toContain('20m')
        ->and($data['labels'])->toContain('40m')
        ->and(array_sum($data['datasets'][0]['data']))->toBe(8);
});

test('qsos_per_band orders by band sort_order', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['40m']->id,
        'mode_id' => $modes['CW']->id,
        'is_duplicate' => false,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_band'];
    $chart->widgetId = 'test-qpb-order';

    $data = $chart->getData();

    $index20m = array_search('20m', $data['labels']);
    $index40m = array_search('40m', $data['labels']);

    expect($index20m)->toBeLessThan($index40m);
});

test('qsos_per_band excludes duplicate contacts', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(4)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => true,
        'points' => 0,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_band'];
    $chart->widgetId = 'test-qpb-dupes';

    $data = $chart->getData();

    expect(array_sum($data['datasets'][0]['data']))->toBe(4);
});

// ────────────────────────────────────────────────────────────────
// QSOs per Mode data source
// ────────────────────────────────────────────────────────────────

test('qsos_per_mode returns correct counts grouped by mode', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(4)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(6)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['CW']->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['FT8']->id,
        'is_duplicate' => false,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'pie', 'data_source' => 'qsos_per_mode'];
    $chart->widgetId = 'test-qpm';

    $data = $chart->getData();

    expect($data['labels'])->toContain('SSB')
        ->and($data['labels'])->toContain('CW')
        ->and($data['labels'])->toContain('FT8')
        ->and(array_sum($data['datasets'][0]['data']))->toBe(12);
});

test('qsos_per_mode orders by count descending', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(10)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['CW']->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'pie', 'data_source' => 'qsos_per_mode'];
    $chart->widgetId = 'test-qpm-order';

    $data = $chart->getData();

    expect($data['labels'][0])->toBe('CW')
        ->and($data['datasets'][0]['data'][0])->toBe(10);
});

test('qsos_per_mode excludes duplicate contacts', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => true,
        'points' => 0,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'pie', 'data_source' => 'qsos_per_mode'];
    $chart->widgetId = 'test-qpm-dupes';

    $data = $chart->getData();

    expect(array_sum($data['datasets'][0]['data']))->toBe(5);
});

// ────────────────────────────────────────────────────────────────
// Caching
// ────────────────────────────────────────────────────────────────

test('getData caches results for 5 seconds', function () {
    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = 'test-cache';

    $cacheKey = $chart->cacheKey();
    Cache::forget($cacheKey);

    expect(Cache::has($cacheKey))->toBeFalse();

    $chart->getData();

    expect(Cache::has($cacheKey))->toBeTrue();
});

test('cached data is returned on subsequent calls', function () {
    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'];
    $chart->widgetId = 'test-cache-hit';

    $cacheKey = $chart->cacheKey();
    Cache::forget($cacheKey);

    $data1 = $chart->getData();
    $data2 = $chart->getData();

    expect($data1)->toBe($data2);
});

// ────────────────────────────────────────────────────────────────
// Widget listeners
// ────────────────────────────────────────────────────────────────

test('getWidgetListeners returns empty array', function () {
    $chart = new Chart;

    expect($chart->getWidgetListeners())->toBe([]);
});

// ────────────────────────────────────────────────────────────────
// Accessibility description
// ────────────────────────────────────────────────────────────────

test('description includes total QSO count', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(7)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_band'];
    $chart->widgetId = 'test-desc';

    $data = $chart->getData();

    expect($data['description'])->toContain('7 total QSOs');
});

test('description shows no data message when empty', function () {
    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_band'];
    $chart->widgetId = 'test-desc-empty';

    $data = $chart->getData();

    expect($data['description'])->toContain('No data available');
});

// ────────────────────────────────────────────────────────────────
// View rendering
// ────────────────────────────────────────────────────────────────

test('view contains canvas element', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
        'size' => 'normal',
    ])
        ->assertSeeHtml('x-ref="canvas"');
});

test('view contains screen reader data table', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
        'size' => 'normal',
    ])
        ->assertSeeHtml('role="table"')
        ->assertSeeHtml('class="sr-only"');
});

test('view shows empty state when no data', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
        'size' => 'normal',
    ])
        ->assertSee('No data available');
});

test('view uses chart title from data', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_band'],
        'size' => 'normal',
    ])
        ->assertSee('QSOs per Band');
});

test('tv size uses shadow-lg class', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
        'size' => 'tv',
    ])
        ->assertSeeHtml('shadow-lg');
});

test('normal size uses shadow-sm class', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
        'size' => 'normal',
    ])
        ->assertSeeHtml('shadow-sm');
});

test('tv size uses larger minimum height', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
        'size' => 'tv',
    ])
        ->assertSeeHtml('min-h-[280px]');
});

test('normal size uses standard minimum height', function () {
    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_hour'],
        'size' => 'normal',
    ])
        ->assertSeeHtml('min-h-[200px]');
});

test('screen reader table includes data rows when contacts exist', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
    ]);

    Livewire::test(Chart::class, [
        'config' => ['chart_type' => 'bar', 'data_source' => 'qsos_per_band'],
        'size' => 'normal',
    ])
        ->assertSee('20m');
});

// ────────────────────────────────────────────────────────────────
// Default config handling
// ────────────────────────────────────────────────────────────────

test('missing config keys use defaults', function () {
    $chart = new Chart;
    $chart->config = [];
    $chart->widgetId = 'test-defaults';

    $data = $chart->getData();

    expect($data['chart_type'])->toBe('bar')
        ->and($data['data_source'])->toBe('qsos_per_hour');
});

// ────────────────────────────────────────────────────────────────
// Event without configuration
// ────────────────────────────────────────────────────────────────

test('returns empty data when event has no configuration', function () {
    EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day']
    );

    Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $chart = new Chart;
    $chart->config = ['chart_type' => 'bar', 'data_source' => 'qsos_per_band'];
    $chart->widgetId = 'test-no-config';

    $data = $chart->getData();

    expect($data['labels'])->toBeEmpty();
});

test('time_range filter excludes contacts older than the lookback window', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    // 3 contacts inside the 1-hour window
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
        'qso_time' => now()->subMinutes(15),
    ]);

    // 5 contacts outside the 1-hour window
    Contact::factory()->count(5)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
        'qso_time' => now()->subHours(3),
    ]);

    $chart = new Chart;
    $chart->config = [
        'chart_type' => 'bar',
        'data_source' => 'qsos_per_band',
        'time_range' => 'last_hour',
    ];
    $chart->widgetId = 'test-time-range';

    $data = $chart->getData();

    expect(array_sum($data['datasets'][0]['data']))->toBe(3)
        ->and($data['title'])->toBe('QSOs per Band — Last Hour');
});

test('invalid time_range falls back to entire event', function () {
    ['eventConfig' => $eventConfig] = createActiveEventWithConfig();
    $bands = createTestBands();
    $modes = createTestModes();

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $eventConfig->id,
        'band_id' => $bands['20m']->id,
        'mode_id' => $modes['SSB']->id,
        'is_duplicate' => false,
        'qso_time' => now()->subHours(6),
    ]);

    $chart = new Chart;
    $chart->config = [
        'chart_type' => 'bar',
        'data_source' => 'qsos_per_band',
        'time_range' => 'last_century',
    ];
    $chart->widgetId = 'test-time-range-invalid';

    $data = $chart->getData();

    expect(array_sum($data['datasets'][0]['data']))->toBe(2)
        ->and($data['title'])->toBe('QSOs per Band — Entire Event');
});
