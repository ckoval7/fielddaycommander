<?php

use App\Livewire\Components\WeatherAlertBanner;
use App\Models\Setting;
use Livewire\Livewire;

test('banner is hidden when there are no alerts', function () {
    Setting::set('weather.alerts', []);

    Livewire::test(WeatherAlertBanner::class)
        ->assertDontSee('alert-warning')
        ->assertDontSee('alert-error');
});

test('banner shows NWS alert headline', function () {
    Setting::set('weather.alerts', [[
        'event' => 'Severe Thunderstorm Warning',
        'headline' => 'Severe Thunderstorm Warning for New Haven County',
        'description' => 'Damaging winds expected.',
        'severity' => 'Severe',
        'expires' => null,
    ]]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Severe Thunderstorm Warning for New Haven County');
});

test('banner shows multiple NWS alerts', function () {
    Setting::set('weather.alerts', [
        ['event' => 'Tornado Warning', 'headline' => 'Tornado Warning headline', 'description' => '', 'severity' => 'Extreme', 'expires' => null],
        ['event' => 'Flash Flood Watch', 'headline' => 'Flash Flood Watch headline', 'description' => '', 'severity' => 'Moderate', 'expires' => null],
    ]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Tornado Warning headline')
        ->assertSee('Flash Flood Watch headline');
});

test('banner hides after user dismisses it', function () {
    Setting::set('weather.alerts', [[
        'event' => 'High Wind Warning',
        'headline' => 'High Wind Warning in effect',
        'description' => '',
        'severity' => 'Severe',
        'expires' => null,
    ]]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('High Wind Warning in effect')
        ->call('dismiss')
        ->assertDontSee('High Wind Warning in effect');
});

test('banner reappears when new alert arrives with different fingerprint', function () {
    $initialAlerts = [['event' => 'High Wind Warning', 'headline' => 'Wind warning', 'description' => '', 'severity' => 'Severe', 'expires' => null]];
    Setting::set('weather.alerts', $initialAlerts);

    $component = Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Wind warning')
        ->call('dismiss')
        ->assertDontSee('Wind warning');

    // New different alert arrives via broadcast
    $newAlerts = [['alerts' => [['event' => 'Tornado Warning', 'headline' => 'Tornado incoming', 'description' => '', 'severity' => 'Extreme', 'expires' => null]], 'has_alerts' => true, 'manual' => false]];

    $component->dispatch('echo:weather,WeatherAlertChanged', ...$newAlerts)
        ->assertSee('Tornado incoming');
});

test('manual alerts use Local Alert label on initial render', function () {
    // setManualAlert() stores event = 'Local Alert'; mount() detects this and sets $manual = true
    Setting::set('weather.alerts', [[
        'event' => 'Local Alert',
        'headline' => 'Lightning within 10 miles — seek shelter',
        'description' => 'Lightning within 10 miles — seek shelter',
        'severity' => 'Severe',
        'expires' => null,
    ]]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Local Alert')
        ->assertSee('Lightning within 10 miles');
});
