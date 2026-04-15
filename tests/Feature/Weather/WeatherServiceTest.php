<?php

use App\Events\WeatherAlertChanged;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Services\ActiveEventService;
use App\Services\WeatherService;
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

// --- checkAlerts ---

test('checkAlerts stores filtered alerts and broadcasts when fingerprint changes', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    Http::fake([
        'api.weather.gov/*' => Http::response([
            'features' => [
                [
                    'properties' => [
                        'event' => 'Severe Thunderstorm Warning',
                        'headline' => 'Warning issued for New Haven County',
                        'description' => 'A severe thunderstorm capable of producing...',
                        'severity' => 'Severe',
                        'expires' => '2026-04-14T20:00:00-04:00',
                    ],
                ],
                [
                    'properties' => [
                        'event' => 'Air Quality Alert', // should be filtered out
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
    $service->checkAlerts(41.3083, -72.9279, 'CT');

    $alerts = Setting::get('weather.alerts');
    expect($alerts)->toHaveCount(1);
    expect($alerts[0]['event'])->toBe('Severe Thunderstorm Warning');

    EventFacade::assertDispatched(WeatherAlertChanged::class, function ($event) {
        return $event->hasAlerts === true && $event->manual === false;
    });
});

test('checkAlerts does not broadcast when fingerprint is unchanged', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

    $existingAlerts = [['event' => 'Tornado Warning', 'headline' => 'Test']];
    Setting::set('weather.alert_fingerprint', md5(json_encode($existingAlerts)));

    Http::fake([
        'api.weather.gov/*' => Http::response([
            'features' => [
                [
                    'properties' => [
                        'event' => 'Tornado Warning',
                        'headline' => 'Test',
                        'description' => '',
                        'severity' => 'Extreme',
                        'expires' => null,
                    ],
                ],
            ],
        ], 200),
    ]);

    // The stored fingerprint matches what the API returns after filtering
    // Rebuild the expected fingerprint to match what checkAlerts will compute
    $expectedAlerts = [['event' => 'Tornado Warning', 'headline' => 'Test', 'description' => '', 'severity' => 'Extreme', 'expires' => null]];
    Setting::set('weather.alert_fingerprint', md5(json_encode($expectedAlerts)));

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279, 'CT');

    EventFacade::assertNotDispatched(WeatherAlertChanged::class);
});

test('checkAlerts stores empty array and broadcasts all-clear when no relevant alerts', function () {
    EventFacade::fake([WeatherAlertChanged::class]);
    Setting::set('weather.alert_fingerprint', md5(json_encode([['event' => 'Old Alert']]))); // Different fingerprint

    Http::fake([
        'api.weather.gov/*' => Http::response(['features' => []], 200),
    ]);

    $service = makeWeatherService();
    $service->checkAlerts(41.3083, -72.9279, 'CT');

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
