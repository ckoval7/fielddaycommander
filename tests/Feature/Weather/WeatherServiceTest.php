<?php

use App\Events\WeatherAlertChanged;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Services\ActiveEventService;
use App\Services\WeatherService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function makeActiveWeatherEvent(array $configAttrs = []): EventConfiguration
{
    $event = Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);

    return EventConfiguration::factory()->create(array_merge([
        'event_id' => $event->id,
        'latitude' => 41.3083,
        'longitude' => -72.9279,
        'state' => 'CT',
    ], $configAttrs));
}

function makeUpcomingWeatherEvent(array $configAttrs = []): EventConfiguration
{
    $event = Event::factory()->create([
        'start_time' => now()->addHours(6),
        'end_time' => now()->addHours(30),
    ]);

    return EventConfiguration::factory()->create(array_merge([
        'event_id' => $event->id,
        'latitude' => 41.3083,
        'longitude' => -72.9279,
        'state' => 'CT',
    ], $configAttrs));
}

function makeWeatherService(): WeatherService
{
    // ActiveEventService is a singleton — clear its cache between tests
    $service = app(ActiveEventService::class);
    $service->clearCache();

    return app(WeatherService::class);
}

// --- getActiveEventCoordinates ---

test('getActiveEventCoordinates returns null when no active event', function () {
    $service = makeWeatherService();

    expect($service->getActiveEventCoordinates())->toBeNull();
});

test('getActiveEventCoordinates returns null when event has no location', function () {
    makeActiveWeatherEvent(['latitude' => null, 'longitude' => null, 'state' => null]);
    $service = makeWeatherService();

    expect($service->getActiveEventCoordinates())->toBeNull();
});

test('getActiveEventCoordinates returns coordinates from active event', function () {
    makeActiveWeatherEvent();
    $service = makeWeatherService();

    $coords = $service->getActiveEventCoordinates();

    expect($coords)->toMatchArray([
        'lat' => 41.3083,
        'lon' => -72.9279,
        'state' => 'CT',
    ]);
});

test('getActiveEventCoordinates returns coordinates from upcoming event', function () {
    makeUpcomingWeatherEvent();
    $service = makeWeatherService();

    $coords = $service->getActiveEventCoordinates();

    expect($coords)->toMatchArray([
        'lat' => 41.3083,
        'lon' => -72.9279,
        'state' => 'CT',
    ]);
});

test('getActiveEventCoordinates returns null for completed event', function () {
    Event::factory()
        ->has(EventConfiguration::factory()->state([
            'latitude' => 41.3083,
            'longitude' => -72.9279,
            'state' => 'CT',
        ]))
        ->create([
            'start_time' => now()->subHours(25),
            'end_time' => now()->subHour(),
        ]);

    $service = makeWeatherService();

    expect($service->getActiveEventCoordinates())->toBeNull();
});

// --- fetchForecast ---

test('fetchForecast stores data in system_config', function () {
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'current' => [
                'temperature_2m' => 72.5,
                'wind_speed_10m' => 12.0,
                'wind_gusts_10m' => 18.5,
                'precipitation' => 0.0,
                'weather_code' => 2,
            ],
            'hourly' => ['time' => [], 'temperature_2m' => []],
            'daily' => ['time' => [], 'temperature_2m_max' => []],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->fetchForecast(41.3083, -72.9279);

    $stored = Setting::get('weather.forecast');
    expect($stored)->not->toBeNull();
    expect($stored['current']['temperature_2m'])->toBe(72.5);
    expect(Setting::get('weather.last_fetch'))->not->toBeNull();
});

test('fetchForecast logs warning on API failure and does not throw', function () {
    Http::fake([
        'api.open-meteo.com/*' => Http::response([], 503),
    ]);

    Log::shouldReceive('warning')->once()->with('Open-Meteo API error', Mockery::any());

    $service = makeWeatherService();
    $service->fetchForecast(41.3083, -72.9279);

    expect(Setting::get('weather.forecast'))->toBeNull();
});

test('fetchForecast sends imperial units to Open-Meteo by default', function () {
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'current' => ['temperature_2m' => 72.5, 'wind_speed_10m' => 12.0, 'wind_gusts_10m' => 0.0, 'precipitation' => 0.0, 'weather_code' => 0],
            'hourly' => ['time' => [], 'temperature_2m' => []],
            'daily' => ['time' => [], 'temperature_2m_max' => []],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->fetchForecast(41.3083, -72.9279);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'api.open-meteo.com')
            && ($data['temperature_unit'] ?? null) === 'fahrenheit'
            && ($data['wind_speed_unit'] ?? null) === 'mph';
    });
});

test('fetchForecast sends metric units to Open-Meteo when setting is metric', function () {
    Setting::set('weather.units', 'metric');

    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'current' => ['temperature_2m' => 22.0, 'wind_speed_10m' => 20.0, 'wind_gusts_10m' => 0.0, 'precipitation' => 0.0, 'weather_code' => 0],
            'hourly' => ['time' => [], 'temperature_2m' => []],
            'daily' => ['time' => [], 'temperature_2m_max' => []],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->fetchForecast(41.3083, -72.9279);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'api.open-meteo.com')
            && ($data['temperature_unit'] ?? null) === 'celsius'
            && ($data['wind_speed_unit'] ?? null) === 'kmh';
    });
});

test('fetchForecast sends imperial units when setting is explicitly imperial', function () {
    Setting::set('weather.units', 'imperial');

    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'current' => ['temperature_2m' => 72.5, 'wind_speed_10m' => 12.0, 'wind_gusts_10m' => 0.0, 'precipitation' => 0.0, 'weather_code' => 0],
            'hourly' => ['time' => [], 'temperature_2m' => []],
            'daily' => ['time' => [], 'temperature_2m_max' => []],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->fetchForecast(41.3083, -72.9279);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), 'api.open-meteo.com')
            && ($data['temperature_unit'] ?? null) === 'fahrenheit'
            && ($data['wind_speed_unit'] ?? null) === 'mph';
    });
});

// --- checkAlerts ---

test('checkAlerts stores filtered alerts and broadcasts when fingerprint changes', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response([
            'features' => [
                [
                    'geometry' => null,
                    'properties' => [
                        'event' => 'Severe Thunderstorm Warning',
                        'headline' => 'Warning issued for New Haven County',
                        'description' => 'A severe thunderstorm capable of producing...',
                        'severity' => 'Severe',
                        'expires' => '2026-04-14T20:00:00-04:00',
                    ],
                ],
                [
                    'geometry' => null,
                    'properties' => [
                        'event' => 'Air Quality Alert', // filtered out
                        'headline' => 'Air Quality Alert',
                        'description' => '...',
                        'severity' => 'Minor',
                        'expires' => '2026-04-14T20:00:00-04:00',
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    $alerts = Setting::get('weather.alerts');
    expect($alerts)->toHaveCount(1);
    expect($alerts[0]['event'])->toBe('Severe Thunderstorm Warning');
    expect($alerts[0]['severity_level'])->toBe('yellow');

    EventFacade::assertDispatched(WeatherAlertChanged::class, function ($event) {
        return $event->hasAlerts === true && $event->manual === false;
    });
});

test('checkAlerts does not broadcast when fingerprint is unchanged', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response([
            'features' => [[
                'geometry' => null,
                'properties' => [
                    'event' => 'Tornado Warning',
                    'headline' => 'Test',
                    'description' => '',
                    'severity' => 'Extreme',
                    'expires' => null,
                ],
            ]],
        ], 200),
    ]);

    // Fingerprint must include severity_level to match what checkAlerts now computes
    $expectedAlerts = [['event' => 'Tornado Warning', 'headline' => 'Test', 'description' => '', 'severity' => 'Extreme', 'expires' => null, 'severity_level' => 'yellow']];
    Setting::set('weather.alert_fingerprint', md5(json_encode($expectedAlerts)));

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    EventFacade::assertNotDispatched(WeatherAlertChanged::class);
});

test('checkAlerts stores empty array and broadcasts all-clear when no relevant alerts', function () {
    EventFacade::fake([WeatherAlertChanged::class]);
    Setting::set('weather.alert_fingerprint', md5(json_encode([['event' => 'Old Alert']])));

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response(['features' => []], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    expect(Setting::get('weather.alerts'))->toBeEmpty();

    EventFacade::assertDispatched(WeatherAlertChanged::class, function ($event) {
        return $event->hasAlerts === false;
    });
});

// --- getDisplayData ---

test('getDisplayData returns forecast data when no manual override', function () {
    Setting::set('weather.forecast', ['current' => ['temperature_2m' => 68.0]]);

    $service = makeWeatherService();
    $data = $service->getDisplayData();

    expect($data['manual'])->toBeFalse();
    expect($data['data']['current']['temperature_2m'])->toEqual(68.0);
});

test('getDisplayData returns manual override when active', function () {
    Setting::set('weather.forecast', ['current' => ['temperature_2m' => 68.0]]);
    Setting::set('weather.manual_override', [
        'temperature' => 72,
        'wind_speed' => 10,
        'wind_direction' => 'SW',
        'precipitation_chance' => 20,
        'notes' => 'Test note',
        'updated_by' => 'W1AW',
        'updated_at' => '2026-04-14T14:00:00Z',
    ]);

    $service = makeWeatherService();
    $data = $service->getDisplayData();

    expect($data['manual'])->toBeTrue();
    expect($data['data']['temperature'])->toBe(72);
});

// --- manual override ---

test('setManualOverride writes to system_config', function () {
    $service = makeWeatherService();
    $service->setManualOverride(['temperature' => 75, 'wind_speed' => 8, 'wind_direction' => 'N', 'precipitation_chance' => 10, 'notes' => '', 'updated_by' => 'W1AW', 'updated_at' => now()->toIso8601String()]);

    expect(Setting::get('weather.manual_override'))->not->toBeNull();
    expect(Setting::get('weather.manual_override')['temperature'])->toBe(75);
});

test('clearManualOverride removes the override', function () {
    Setting::set('weather.manual_override', ['temperature' => 75]);

    $service = makeWeatherService();
    $service->clearManualOverride();

    expect(Setting::get('weather.manual_override'))->toBeNull();
});

// --- manual alert ---

test('setManualAlert broadcasts WeatherAlertChanged with manual flag', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    $service = makeWeatherService();
    $service->setManualAlert('Lightning within 10 miles — seek shelter immediately');

    EventFacade::assertDispatched(WeatherAlertChanged::class, function ($event) {
        return $event->hasAlerts === true && $event->manual === true;
    });

    $alerts = Setting::get('weather.alerts');
    expect($alerts[0]['headline'])->toBe('Lightning within 10 miles — seek shelter immediately');
});

test('clearManualAlert broadcasts all-clear with manual flag', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    $service = makeWeatherService();
    $service->clearManualAlert();

    EventFacade::assertDispatched(WeatherAlertChanged::class, function ($event) {
        return $event->hasAlerts === false && $event->manual === true;
    });
});

test('fetchForecast writes success status to cache', function () {
    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'current' => ['temperature_2m' => 72.5, 'wind_speed_10m' => 12.0, 'wind_gusts_10m' => 18.5, 'precipitation' => 0.0, 'weather_code' => 2],
            'hourly' => ['time' => [], 'temperature_2m' => []],
            'daily' => ['time' => [], 'temperature_2m_max' => []],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->fetchForecast(41.3083, -72.9279);

    $status = cache()->get('weather.forecast_status');
    expect($status)->not->toBeNull();
    expect($status['success'])->toBeTrue();
    expect($status['error'])->toBeNull();
    expect($status['last_attempt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]/');
});

test('fetchForecast writes error status to cache on API failure', function () {
    Http::fake([
        'api.open-meteo.com/*' => Http::response([], 503),
    ]);

    Log::shouldReceive('warning')->once()->with('Open-Meteo API error', Mockery::any());

    $service = makeWeatherService();
    $service->fetchForecast(41.3083, -72.9279);

    $status = cache()->get('weather.forecast_status');
    expect($status)->not->toBeNull();
    expect($status['success'])->toBeFalse();
    expect($status['error'])->toBe('HTTP 503');
    expect($status['last_attempt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]/');
});

test('fetchForecast writes error status to cache on exception', function () {
    Http::fake([
        'api.open-meteo.com/*' => fn () => throw new ConnectionException('timeout'),
    ]);

    Log::shouldReceive('error')->once();

    $service = makeWeatherService();
    $service->fetchForecast(41.3083, -72.9279);

    $status = cache()->get('weather.forecast_status');
    expect($status)->not->toBeNull();
    expect($status['success'])->toBeFalse();
    expect($status['error'])->toBe('timeout');
    expect($status['last_attempt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]/');
});

test('checkAlerts writes success status to cache', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response(['features' => []], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    $status = cache()->get('weather.alerts_status');
    expect($status)->not->toBeNull();
    expect($status['success'])->toBeTrue();
    expect($status['error'])->toBeNull();
    expect($status['last_attempt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]/');
});

test('checkAlerts writes error status to cache on API failure', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response([], 503),
    ]);

    Log::shouldReceive('warning')->once()->with('NWS API error', Mockery::any());

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    $status = cache()->get('weather.alerts_status');
    expect($status)->not->toBeNull();
    expect($status['success'])->toBeFalse();
    expect($status['error'])->toBe('HTTP 503');
    expect($status['last_attempt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]/');
});

test('checkAlerts writes error status to cache on exception', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => fn () => throw new ConnectionException('timeout'),
    ]);

    Log::shouldReceive('error')->once();

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    $status = cache()->get('weather.alerts_status');
    expect($status)->not->toBeNull();
    expect($status['success'])->toBeFalse();
    expect($status['error'])->toBe('timeout');
    expect($status['last_attempt'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]/');
});

// --- enable/disable Open-Meteo ---

test('isOpenMeteoEnabled returns true by default', function () {
    $service = makeWeatherService();

    expect($service->isOpenMeteoEnabled())->toBeTrue();
});

test('disableOpenMeteo sets flag to false and clears forecast data', function () {
    Setting::set('weather.forecast', ['current' => ['temperature_2m' => 72.5]]);
    Setting::set('weather.last_fetch', now()->toIso8601String());

    $service = makeWeatherService();
    $service->disableOpenMeteo();

    expect($service->isOpenMeteoEnabled())->toBeFalse();
    expect(Setting::get('weather.forecast'))->toBeNull();
    expect(Setting::get('weather.last_fetch'))->toBeNull();
});

test('enableOpenMeteo sets flag to true', function () {
    Setting::set('weather.openmeteo_enabled', false);

    $service = makeWeatherService();
    $service->enableOpenMeteo();

    expect($service->isOpenMeteoEnabled())->toBeTrue();
});

// --- enable/disable NWS ---

test('isNwsEnabled returns true by default', function () {
    $service = makeWeatherService();

    expect($service->isNwsEnabled())->toBeTrue();
});

test('disableNws sets flag to false and clears NWS alerts', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Setting::set('weather.alerts', [
        ['event' => 'Severe Thunderstorm Warning', 'headline' => 'NWS alert', 'description' => '', 'severity' => 'Severe', 'expires' => null],
    ]);
    Setting::set('weather.alert_fingerprint', 'abc123');

    $service = makeWeatherService();
    $service->disableNws();

    expect($service->isNwsEnabled())->toBeFalse();
    expect(Setting::get('weather.alerts'))->toBeEmpty();

    EventFacade::assertDispatched(WeatherAlertChanged::class, fn ($e) => $e->hasAlerts === false && $e->manual === false);
});

test('disableNws does not clear alerts when a manual alert is active', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Setting::set('weather.alerts', [
        ['event' => 'Local Alert', 'headline' => 'Lightning within 10 miles', 'description' => 'Lightning within 10 miles', 'severity' => 'Severe', 'expires' => null],
    ]);

    $service = makeWeatherService();
    $service->disableNws();

    expect(Setting::get('weather.alerts'))->toHaveCount(1);
    EventFacade::assertNotDispatched(WeatherAlertChanged::class);
});

test('enableNws sets flag to true', function () {
    Setting::set('weather.nws_enabled', false);

    $service = makeWeatherService();
    $service->enableNws();

    expect($service->isNwsEnabled())->toBeTrue();
});

// --- API guards ---

test('fetchForecast skips API call and does not update data when Open-Meteo is disabled', function () {
    Http::fake();
    Setting::set('weather.openmeteo_enabled', false);
    Setting::set('weather.forecast', null);

    $service = makeWeatherService();
    $service->fetchForecast(41.3083, -72.9279);

    Http::assertNothingSent();
    expect(Setting::get('weather.forecast'))->toBeNull();
});

test('checkAlerts skips API call and does not update data when NWS is disabled', function () {
    EventFacade::fake([WeatherAlertChanged::class]);
    Http::fake();
    Setting::set('weather.nws_enabled', false);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    Http::assertNothingSent();
    EventFacade::assertNotDispatched(WeatherAlertChanged::class);
});

// --- isWeatherPageVisible ---

test('isWeatherPageVisible returns true when Open-Meteo is enabled', function () {
    Setting::set('weather.openmeteo_enabled', true);

    $service = makeWeatherService();

    expect($service->isWeatherPageVisible())->toBeTrue();
});

test('isWeatherPageVisible returns false when Open-Meteo disabled and no manual override', function () {
    Setting::set('weather.openmeteo_enabled', false);
    Setting::set('weather.manual_override', null);

    $service = makeWeatherService();

    expect($service->isWeatherPageVisible())->toBeFalse();
});

test('isWeatherPageVisible returns true when Open-Meteo disabled but manual override is active', function () {
    Setting::set('weather.openmeteo_enabled', false);
    Setting::set('weather.manual_override', [
        'temperature' => 75,
        'wind_speed' => 10,
        'wind_direction' => 'N',
        'precipitation_chance' => 20,
        'notes' => '',
        'updated_by' => 'W1AW',
        'updated_at' => now()->toIso8601String(),
    ]);

    $service = makeWeatherService();

    expect($service->isWeatherPageVisible())->toBeTrue();
});

// --- alert classification (severity_level) ---

test('point inside polygon geometry is classified as red', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response([
            'features' => [[
                'geometry' => [
                    'type' => 'Polygon',
                    // bounding box that contains [41.3083, -72.9279]
                    'coordinates' => [[
                        [-74.0, 40.0], [-71.0, 40.0], [-71.0, 43.0], [-74.0, 43.0], [-74.0, 40.0],
                    ]],
                ],
                'properties' => [
                    'event' => 'Tornado Warning',
                    'headline' => 'Tornado Warning in effect',
                    'description' => 'Tornado spotted.',
                    'severity' => 'Extreme',
                    'expires' => null,
                ],
            ]],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    expect(Setting::get('weather.alerts')[0]['severity_level'])->toBe('red');
});

test('point outside polygon geometry is classified as yellow', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response([
            'features' => [[
                'geometry' => [
                    'type' => 'Polygon',
                    // bounding box that does NOT contain [41.3083, -72.9279]
                    'coordinates' => [[
                        [-80.0, 45.0], [-78.0, 45.0], [-78.0, 47.0], [-80.0, 47.0], [-80.0, 45.0],
                    ]],
                ],
                'properties' => [
                    'event' => 'High Wind Warning',
                    'headline' => 'High Wind Warning',
                    'description' => 'Strong winds.',
                    'severity' => 'Severe',
                    'expires' => null,
                ],
            ]],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    expect(Setting::get('weather.alerts')[0]['severity_level'])->toBe('yellow');
});

test('null geometry is classified as yellow', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response([
            'features' => [[
                'geometry' => null,
                'properties' => [
                    'event' => 'Flash Flood Watch',
                    'headline' => 'Flash Flood Watch',
                    'description' => 'Heavy rain expected.',
                    'severity' => 'Moderate',
                    'expires' => null,
                ],
            ]],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    expect(Setting::get('weather.alerts')[0]['severity_level'])->toBe('yellow');
});

test('point inside any ring of a multipolygon is classified as red', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response([
            'features' => [[
                'geometry' => [
                    'type' => 'MultiPolygon',
                    'coordinates' => [
                        // First polygon: does NOT contain the point
                        [[[-80.0, 45.0], [-78.0, 45.0], [-78.0, 47.0], [-80.0, 47.0], [-80.0, 45.0]]],
                        // Second polygon: DOES contain [41.3083, -72.9279]
                        [[[-74.0, 40.0], [-71.0, 40.0], [-71.0, 43.0], [-74.0, 43.0], [-74.0, 40.0]]],
                    ],
                ],
                'properties' => [
                    'event' => 'Tornado Warning',
                    'headline' => 'Tornado Warning in effect',
                    'description' => 'Tornado spotted.',
                    'severity' => 'Extreme',
                    'expires' => null,
                ],
            ]],
        ], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    expect(Setting::get('weather.alerts')[0]['severity_level'])->toBe('red');
});

// --- fetchNwsPoints (via checkAlerts) ---

test('checkAlerts fetches zone and county from NWS points API on first call', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response(['features' => []], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'api.weather.gov/points/41.3083,-72.9279'));
    Http::assertSent(fn ($req) => str_contains($req->url(), 'zone=CTC003%2CCTZ009')
        || str_contains($req->url(), 'zone=CTC003,CTZ009'));
});

test('checkAlerts uses cached zone and county without hitting points API again', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    cache()->forever('weather.nws_points.41.3083,-72.9279', [
        'zone' => 'CTZ009',
        'county' => 'CTC003',
    ]);

    Http::fake([
        'api.weather.gov/alerts/active*' => Http::response(['features' => []], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.weather.gov/points'));
});

test('checkAlerts logs warning and skips when points API fails', function () {
    Http::fake([
        'api.weather.gov/points/*' => Http::response([], 503),
    ]);

    Log::shouldReceive('warning')->once()->with('NWS points API error', Mockery::any());

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279);

    $status = cache()->get('weather.alerts_status');
    expect($status['success'])->toBeFalse();
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'alerts/active'));
});

test('checkAlerts rounds lat/lon to 4 decimal places for cache key', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecastZone' => 'https://api.weather.gov/zones/forecast/CTZ009',
                'county' => 'https://api.weather.gov/zones/county/CTC003',
            ],
        ], 200),
        'api.weather.gov/alerts/active*' => Http::response(['features' => []], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.308312345, -72.927912345);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'api.weather.gov/points/41.3083,-72.9279'));
    expect(cache()->has('weather.nws_points.41.3083,-72.9279'))->toBeTrue();
});
