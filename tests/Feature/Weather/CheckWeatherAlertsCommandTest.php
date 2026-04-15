<?php

use App\Events\WeatherAlertChanged;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Setting;
use App\Services\ActiveEventService;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    app(ActiveEventService::class)->clearCache();
});

test('it skips when no active or upcoming event has coordinates', function () {
    $this->artisan('weather:check-alerts')
        ->expectsOutputToContain('No active or upcoming event')
        ->assertSuccessful();
});

test('it checks alerts and broadcasts changes for active event', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

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
        'api.weather.gov/*' => Http::response([
            'features' => [[
                'properties' => [
                    'event' => 'Tornado Warning',
                    'headline' => 'Tornado Warning in effect',
                    'description' => 'A tornado has been spotted.',
                    'severity' => 'Extreme',
                    'expires' => '2026-04-14T20:00:00-04:00',
                ],
            ]],
        ], 200),
    ]);

    $this->artisan('weather:check-alerts')
        ->expectsOutputToContain('Weather alerts checked')
        ->assertSuccessful();

    EventFacade::assertDispatched(WeatherAlertChanged::class);
    expect(Setting::get('weather.alerts'))->toHaveCount(1);
});

test('it checks alerts for upcoming event', function () {
    EventFacade::fake([WeatherAlertChanged::class]);

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
        'api.weather.gov/*' => Http::response(['features' => []], 200),
    ]);

    $this->artisan('weather:check-alerts')
        ->expectsOutputToContain('Weather alerts checked')
        ->assertSuccessful();

    expect(Setting::get('weather.alerts'))->toBeEmpty();
});
