<?php

use App\Livewire\Weather\WeatherDashboard;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Setting::set('weather.forecast', []);
    Setting::set('weather.manual_override', null);
    Setting::set('weather.alerts', []);
    Setting::set('weather.last_fetch', null);
    Setting::set('weather.units', 'imperial');
});

test('dashboard is accessible to guests', function () {
    Livewire::test(WeatherDashboard::class)->assertOk();
});

test('dashboard renders for authenticated users', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertOk();
});

test('dashboard shows neutral empty state when no data and user lacks manage-weather', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertSet('hasData', false)
        ->assertSee('No weather data available');
});

test('dashboard shows manage-weather empty state hint when user has permission', function () {
    Permission::firstOrCreate(['name' => 'manage-weather']);
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertSet('hasData', false)
        ->assertSee('No weather data yet');
});

test('dashboard shows current conditions when forecast data is present', function () {
    Setting::set('weather.forecast', [
        'current' => [
            'temperature_2m' => 72.5,
            'wind_speed_10m' => 12.0,
            'wind_gusts_10m' => 18.0,
            'precipitation' => 0.1,
            'weather_code' => 3,
        ],
        'hourly' => ['time' => [], 'temperature_2m' => [], 'precipitation_probability' => [], 'wind_speed_10m' => [], 'weather_code' => [], 'cape' => []],
        'daily' => ['time' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => [], 'wind_speed_10m_max' => [], 'wind_gusts_10m_max' => [], 'weather_code' => []],
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertSet('hasData', true)
        ->assertSee('73°')
        ->assertSee('Overcast');
});

test('dashboard shows active alerts section when alerts are present', function () {
    Setting::set('weather.forecast', [
        'current' => ['temperature_2m' => 70.0, 'wind_speed_10m' => 5.0, 'wind_gusts_10m' => 10.0, 'precipitation' => 0.0, 'weather_code' => 0],
        'hourly' => ['time' => [], 'temperature_2m' => [], 'precipitation_probability' => [], 'wind_speed_10m' => [], 'weather_code' => [], 'cape' => []],
        'daily' => ['time' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => [], 'wind_speed_10m_max' => [], 'wind_gusts_10m_max' => [], 'weather_code' => []],
    ]);
    Setting::set('weather.alerts', [[
        'event' => 'Severe Thunderstorm Warning',
        'headline' => 'Severe Thunderstorm Warning for New Haven County',
        'description' => 'Damaging winds expected.',
        'severity' => 'Severe',
        'expires' => null,
    ]]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertSee('Severe Thunderstorm Warning for New Haven County');
});

test('dashboard hourly strip shows CAPE warning at or above 500', function () {
    Setting::set('weather.forecast', [
        'current' => ['temperature_2m' => 70.0, 'wind_speed_10m' => 5.0, 'wind_gusts_10m' => 10.0, 'precipitation' => 0.0, 'weather_code' => 2],
        'hourly' => [
            'time' => ['2026-06-28T14:00', '2026-06-28T15:00'],
            'temperature_2m' => [70.0, 71.0],
            'precipitation_probability' => [10, 20],
            'wind_speed_10m' => [8.0, 9.0],
            'weather_code' => [2, 2],
            'cape' => [600, 0],
        ],
        'daily' => ['time' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => [], 'wind_speed_10m_max' => [], 'wind_gusts_10m_max' => [], 'weather_code' => []],
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertSee('Elevated Lightning Risk');
});

test('dashboard hourly strip shows significant CAPE warning at or above 1500', function () {
    Setting::set('weather.forecast', [
        'current' => ['temperature_2m' => 70.0, 'wind_speed_10m' => 5.0, 'wind_gusts_10m' => 10.0, 'precipitation' => 0.0, 'weather_code' => 2],
        'hourly' => [
            'time' => ['2026-06-28T14:00'],
            'temperature_2m' => [70.0],
            'precipitation_probability' => [10],
            'wind_speed_10m' => [8.0],
            'weather_code' => [2],
            'cape' => [1600],
        ],
        'daily' => ['time' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => [], 'wind_speed_10m_max' => [], 'wind_gusts_10m_max' => [], 'weather_code' => []],
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertSee('Significant Lightning Risk');
});

test('dashboard hides hourly strip and daily cards when manual override is active', function () {
    Setting::set('weather.manual_override', [
        'temperature' => 75,
        'wind_speed' => 12,
        'wind_direction' => 'SW',
        'precipitation_chance' => 30,
        'notes' => 'Test note',
        'updated_by' => 'N1ABC',
        'updated_at' => now()->toIso8601String(),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertSet('isManual', true)
        ->assertDontSee('Next 12 Hours')
        ->assertDontSee('3-Day Forecast');
});

test('dashboard shows manual override active in footer', function () {
    Setting::set('weather.manual_override', [
        'temperature' => 75,
        'wind_speed' => 12,
        'wind_direction' => 'SW',
        'precipitation_chance' => 30,
        'notes' => '',
        'updated_by' => 'W1ABC',
        'updated_at' => now()->toIso8601String(),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertSet('isManual', true)
        ->assertSee('Manual override active')
        ->assertSee('W1ABC');
});

test('dashboard shows last fetch time in footer when using forecast data', function () {
    Setting::set('weather.forecast', [
        'current' => ['temperature_2m' => 70.0, 'wind_speed_10m' => 5.0, 'wind_gusts_10m' => 10.0, 'precipitation' => 0.0, 'weather_code' => 0],
        'hourly' => ['time' => [], 'temperature_2m' => [], 'precipitation_probability' => [], 'wind_speed_10m' => [], 'weather_code' => [], 'cape' => []],
        'daily' => ['time' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => [], 'wind_speed_10m_max' => [], 'wind_gusts_10m_max' => [], 'weather_code' => []],
    ]);
    Setting::set('weather.last_fetch', now()->subMinutes(5)->toIso8601String());

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherDashboard::class)
        ->assertSee('Open-Meteo');
});

test('dashboard exposes mph windUnit by default', function () {
    Setting::set('weather.forecast', [
        'current' => ['temperature_2m' => 72.5, 'wind_speed_10m' => 12.0, 'wind_gusts_10m' => 18.0, 'precipitation' => 0.0, 'weather_code' => 0],
        'hourly' => ['time' => [], 'temperature_2m' => [], 'precipitation_probability' => [], 'wind_speed_10m' => [], 'weather_code' => [], 'cape' => []],
        'daily' => ['time' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => [], 'wind_speed_10m_max' => [], 'wind_gusts_10m_max' => [], 'weather_code' => []],
    ]);

    Livewire::test(WeatherDashboard::class)
        ->assertSet('windUnit', 'mph')
        ->assertSee('mph');
});

test('dashboard exposes km/h windUnit when metric setting is active', function () {
    Setting::set('weather.units', 'metric');
    Setting::set('weather.forecast', [
        'current' => ['temperature_2m' => 22.0, 'wind_speed_10m' => 20.0, 'wind_gusts_10m' => 30.0, 'precipitation' => 0.0, 'weather_code' => 0],
        'hourly' => ['time' => [], 'temperature_2m' => [], 'precipitation_probability' => [], 'wind_speed_10m' => [], 'weather_code' => [], 'cape' => []],
        'daily' => ['time' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => [], 'wind_speed_10m_max' => [], 'wind_gusts_10m_max' => [], 'weather_code' => []],
    ]);

    Livewire::test(WeatherDashboard::class)
        ->assertSet('windUnit', 'km/h')
        ->assertSee('km/h');
});

// --- Open-Meteo disabled redirect ---

test('dashboard redirects to dashboard route when Open-Meteo disabled and no manual override', function () {
    Setting::set('weather.openmeteo_enabled', false);
    Setting::set('weather.manual_override', null);

    Livewire::test(WeatherDashboard::class)
        ->assertRedirect(route('dashboard'));
});

test('dashboard does not redirect when Open-Meteo disabled but manual override is active', function () {
    Setting::set('weather.openmeteo_enabled', false);
    Setting::set('weather.manual_override', [
        'temperature' => 75,
        'wind_speed' => 12,
        'wind_direction' => 'SW',
        'precipitation_chance' => 30,
        'notes' => '',
        'updated_by' => 'W1AW',
        'updated_at' => now()->toIso8601String(),
    ]);

    Livewire::test(WeatherDashboard::class)
        ->assertOk();
});

test('dashboard does not redirect when Open-Meteo is enabled', function () {
    Setting::set('weather.openmeteo_enabled', true);
    Setting::set('weather.manual_override', null);

    Livewire::test(WeatherDashboard::class)
        ->assertOk();
});
