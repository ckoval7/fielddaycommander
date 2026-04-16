<?php

use App\Livewire\Components\WeatherIcon;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Setting::set('weather.forecast', []);
    Setting::set('weather.manual_override', null);
});

test('icon renders nothing when no data and user lacks manage-weather', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherIcon::class)
        ->assertSet('hasData', false)
        ->assertSet('canManageWeather', false)
        ->assertDontSeeHtml('weather.index');
});

test('icon renders grayed-out cloud when no data and user has manage-weather', function () {
    Permission::firstOrCreate(['name' => 'manage-weather']);
    $user = User::factory()->create();
    $user->givePermissionTo('manage-weather');

    Livewire::actingAs($user)
        ->test(WeatherIcon::class)
        ->assertSet('hasData', false)
        ->assertSet('canManageWeather', true)
        ->assertSeeHtml('opacity-40');
});

test('icon shows temperature when forecast data present', function () {
    Setting::set('weather.forecast', [
        'current' => [
            'temperature_2m' => 72.5,
            'wind_speed_10m' => 10.0,
            'wind_gusts_10m' => 15.0,
            'precipitation' => 0.0,
            'weather_code' => 0,
        ],
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherIcon::class)
        ->assertSet('hasData', true)
        ->assertSee('73°');
});

test('icon shows M badge when manual override is active', function () {
    Setting::set('weather.manual_override', [
        'temperature' => 75,
        'wind_speed' => 10,
        'wind_direction' => 'SW',
        'precipitation_chance' => 20,
        'notes' => '',
        'updated_by' => 'N1ABC',
        'updated_at' => now()->toIso8601String(),
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherIcon::class)
        ->assertSet('hasData', true)
        ->assertSet('isManual', true)
        ->assertSee('M');
});

test('icon shows wind badge when gusts are 25 mph or more', function () {
    Setting::set('weather.forecast', [
        'current' => [
            'temperature_2m' => 68.0,
            'wind_speed_10m' => 20.0,
            'wind_gusts_10m' => 28.0,
            'precipitation' => 0.0,
            'weather_code' => 2,
        ],
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherIcon::class)
        ->assertSet('gusts', 28.0)
        ->assertSeeHtml('badge-warning');
});

test('icon does not show wind badge when gusts are below 25 mph', function () {
    Setting::set('weather.forecast', [
        'current' => [
            'temperature_2m' => 68.0,
            'wind_speed_10m' => 10.0,
            'wind_gusts_10m' => 18.0,
            'precipitation' => 0.0,
            'weather_code' => 2,
        ],
    ]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WeatherIcon::class)
        ->assertSet('gusts', 18.0)
        ->assertDontSeeHtml('badge-xs font-bold'); // just confirms no M badge rendered
});

test('icon renders night variant when is_day is 0', function () {
    Setting::set('weather.forecast', [
        'current' => [
            'temperature_2m' => 58.0,
            'wind_speed_10m' => 5.0,
            'wind_gusts_10m' => 8.0,
            'precipitation' => 0.0,
            'weather_code' => 0,
            'is_day' => 0,
        ],
    ]);

    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(WeatherIcon::class)
        ->assertSet('isNight', true);

    expect($component->instance()->iconName())->toBe('phosphor-moon-duotone');
});

test('icon renders day variant when is_day is 1', function () {
    Setting::set('weather.forecast', [
        'current' => [
            'temperature_2m' => 72.0,
            'wind_speed_10m' => 5.0,
            'wind_gusts_10m' => 8.0,
            'precipitation' => 0.0,
            'weather_code' => 2,
            'is_day' => 1,
        ],
    ]);

    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(WeatherIcon::class)
        ->assertSet('isNight', false);

    expect($component->instance()->iconName())->toBe('phosphor-cloud-sun-duotone');
});
