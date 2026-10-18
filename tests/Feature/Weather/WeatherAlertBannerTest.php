<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Components\WeatherAlertBanner;
use App\Models\Setting;
use Livewire\Livewire;

test('banner is hidden when there are no alerts', function () {
    Setting::set('weather.alerts', []);

    Livewire::test(WeatherAlertBanner::class)
        ->assertDontSee('bg-amber-100')
        ->assertDontSee('alert-error');
});

test('yellow alert shows Nearby Danger label', function () {
    Setting::set('weather.alerts', [[
        'event' => 'Severe Thunderstorm Warning',
        'headline' => 'Severe Thunderstorm Warning for New Haven County',
        'description' => 'Damaging winds expected.',
        'severity' => 'Severe',
        'expires' => null,
        'severity_level' => 'yellow',
    ]]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Severe Thunderstorm Warning for New Haven County')
        ->assertSee('Nearby Danger');
});

test('red alert shows Immediate Danger label', function () {
    Setting::set('weather.alerts', [[
        'event' => 'Tornado Warning',
        'headline' => 'Tornado Warning in effect',
        'description' => 'Tornado spotted.',
        'severity' => 'Extreme',
        'expires' => null,
        'severity_level' => 'red',
    ]]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Tornado Warning in effect')
        ->assertSee('Immediate Danger');
});

test('red and yellow alerts render as separate rows', function () {
    Setting::set('weather.alerts', [
        ['event' => 'Tornado Warning', 'headline' => 'Tornado Warning headline', 'description' => '', 'severity' => 'Extreme', 'expires' => null, 'severity_level' => 'red'],
        ['event' => 'Flash Flood Watch', 'headline' => 'Flash Flood Watch headline', 'description' => '', 'severity' => 'Moderate', 'expires' => null, 'severity_level' => 'yellow'],
    ]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Tornado Warning headline')
        ->assertSee('Flash Flood Watch headline')
        ->assertSee('Immediate Danger')
        ->assertSee('Nearby Danger');
});

test('banner hides after user dismisses it', function () {
    Setting::set('weather.alerts', [[
        'event' => 'High Wind Warning',
        'headline' => 'High Wind Warning in effect',
        'description' => '',
        'severity' => 'Severe',
        'expires' => null,
        'severity_level' => 'yellow',
    ]]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('High Wind Warning in effect')
        ->call('dismiss')
        ->assertDontSee('High Wind Warning in effect');
});

test('banner reappears when new alert arrives with different fingerprint', function () {
    $initialAlerts = [['event' => 'High Wind Warning', 'headline' => 'Wind warning', 'description' => '', 'severity' => 'Severe', 'expires' => null, 'severity_level' => 'yellow']];
    Setting::set('weather.alerts', $initialAlerts);

    $component = Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Wind warning')
        ->call('dismiss')
        ->assertDontSee('Wind warning');

    $newAlerts = [['alerts' => [['event' => 'Tornado Warning', 'headline' => 'Tornado incoming', 'description' => '', 'severity' => 'Extreme', 'expires' => null, 'severity_level' => 'red']], 'has_alerts' => true]];

    $component->dispatch('echo:weather,WeatherAlertChanged', ...$newAlerts)
        ->assertSee('Tornado incoming');
});

test('manual Local Alert shows Local Alert label', function () {
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

test('local alerts render as red alerts', function () {
    Setting::set('weather.alerts', [[
        'event' => 'Local Alert',
        'headline' => 'Lightning within 10 miles',
        'description' => 'Lightning within 10 miles',
        'severity' => 'Severe',
        'expires' => null,
    ]]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Lightning within 10 miles')
        ->assertSeeHtml('alert alert-error');
});

test('app layout pins the weather alert banner below the header at any scroll position', function () {
    $html = view('components.layouts.app', ['slot' => ''])->render();

    expect($html)
        ->toMatch('/data-weather-alert-sticky\s+class="sticky top-\[var\(--mobile-header-height,0px\)\] lg:top-\[var\(--desktop-header-height,0px\)\]/')
        ->toContain("setProperty('--desktop-header-height'")
        ->toContain("setProperty('--weather-banner-height'");
});
