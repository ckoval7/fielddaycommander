<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Services\ActiveEventService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    app(ActiveEventService::class)->clearCache();
});

test('it skips when no active or upcoming event has coordinates', function () {
    $this->artisan('weather:fetch-forecast')
        ->expectsOutputToContain('No active or upcoming event')
        ->assertSuccessful();

    expect(Setting::get('weather.forecast'))->toBeNull();
});

test('it fetches and stores forecast for active event with coordinates', function () {
    $event = Event::factory()->create([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHours(23),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'latitude' => 41.3083,
        'longitude' => -72.9279,
        'state' => 'CT',
    ]);

    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'current' => ['temperature_2m' => 71.0, 'weather_code' => 1],
            'hourly' => [],
            'daily' => [],
        ], 200),
    ]);

    $this->artisan('weather:fetch-forecast')
        ->expectsOutputToContain('Weather forecast updated')
        ->assertSuccessful();

    expect(Setting::get('weather.forecast'))->not->toBeNull();
});

test('it fetches and stores forecast for upcoming event with coordinates', function () {
    $event = Event::factory()->create([
        'start_time' => now()->addHours(6),
        'end_time' => now()->addHours(30),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'latitude' => 41.3083,
        'longitude' => -72.9279,
        'state' => 'CT',
    ]);

    Http::fake([
        'api.open-meteo.com/*' => Http::response([
            'current' => ['temperature_2m' => 65.0, 'weather_code' => 0],
            'hourly' => [],
            'daily' => [],
        ], 200),
    ]);

    $this->artisan('weather:fetch-forecast')
        ->expectsOutputToContain('Weather forecast updated')
        ->assertSuccessful();

    expect(Setting::get('weather.forecast'))->not->toBeNull();
});
