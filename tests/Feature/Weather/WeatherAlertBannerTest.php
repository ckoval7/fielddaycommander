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
        ->assertSee('Nearby Weather Alert');
});

test('red alert shows Weather Alert at Event Site label', function () {
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
        ->assertSee('Weather Alert at Event Site');
});

test('red and yellow alerts render as separate rows', function () {
    Setting::set('weather.alerts', [
        ['event' => 'Tornado Warning', 'headline' => 'Tornado Warning headline', 'description' => '', 'severity' => 'Extreme', 'expires' => null, 'severity_level' => 'red'],
        ['event' => 'Flash Flood Watch', 'headline' => 'Flash Flood Watch headline', 'description' => '', 'severity' => 'Moderate', 'expires' => null, 'severity_level' => 'yellow'],
    ]);

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Tornado Warning headline')
        ->assertSee('Flash Flood Watch headline')
        ->assertSee('Weather Alert at Event Site')
        ->assertSee('Nearby Weather Alert');
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

    $tornadoAlerts = [['event' => 'Tornado Warning', 'headline' => 'Tornado incoming', 'description' => '', 'severity' => 'Extreme', 'expires' => null, 'severity_level' => 'red']];
    Setting::set('weather.alerts', $tornadoAlerts);
    $newAlerts = [['alerts' => $tornadoAlerts, 'has_alerts' => true]];

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

test('banner in demo mode reads alerts from shared cache when session has none', function () {
    config()->set('demo.enabled', true);
    Setting::set('weather.alerts', []);
    cache()->put('weather:demo:alerts', [[
        'event' => 'Severe Thunderstorm Warning',
        'headline' => 'Shared demo alert',
        'description' => 'desc',
        'severity' => 'Severe',
        'expires' => null,
        'severity_level' => 'yellow',
    ]], now()->addHour());

    Livewire::test(WeatherAlertBanner::class)
        ->assertSee('Shared demo alert');
});

test('banner in demo mode stacks the per-session manual alert above the shared cache', function () {
    config()->set('demo.enabled', true);
    Setting::set('weather.alerts', [[
        'event' => 'Local Alert',
        'headline' => 'Session override',
        'description' => 'desc',
        'severity' => 'Severe',
        'expires' => null,
    ]]);
    cache()->put('weather:demo:alerts', [[
        'event' => 'Severe Thunderstorm Warning',
        'headline' => 'Shared demo alert',
        'description' => 'desc',
        'severity' => 'Severe',
        'expires' => null,
        'severity_level' => 'yellow',
    ]], now()->addHour());

    Livewire::test(WeatherAlertBanner::class)
        ->assertSeeInOrder(['Session override', 'Shared demo alert']);
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

/**
 * @return array<string, mixed>
 */
function nwsAlert(string $event, string $headline, string $severityLevel = 'yellow', ?string $expires = null): array
{
    return ['event' => $event, 'headline' => $headline, 'description' => '', 'severity' => 'Severe', 'expires' => $expires, 'severity_level' => $severityLevel];
}

/**
 * Stores the alerts as the broadcast would, since the banner reloads them
 * from the weather service instead of trusting the payload.
 *
 * @param  list<array<string, mixed>>  $alerts
 * @return array{alerts: list<array<string, mixed>>}
 */
function broadcastAlerts(array $alerts): array
{
    Setting::set('weather.alerts', $alerts);

    return ['alerts' => $alerts];
}

test('dismissed NWS alert stays hidden when NWS reissues it with a new headline and expiry', function () {
    Setting::set('weather.alerts', [nwsAlert('High Wind Warning', 'Wind warning until 4PM', expires: '2026-10-30T16:00:00-04:00')]);

    Livewire::test(WeatherAlertBanner::class)
        ->call('dismiss')
        ->dispatch('echo:weather,WeatherAlertChanged', broadcastAlerts([nwsAlert('High Wind Warning', 'Wind warning until 8PM', expires: '2026-10-30T20:00:00-04:00')]))
        ->assertDontSee('Wind warning until 8PM');
});

test('dismissed alert stays hidden when an unchanged alert list is broadcast', function () {
    $alerts = [nwsAlert('High Wind Warning', 'Wind warning')];
    Setting::set('weather.alerts', $alerts);

    Livewire::test(WeatherAlertBanner::class)
        ->call('dismiss')
        ->dispatch('echo:weather,WeatherAlertChanged', broadcastAlerts($alerts))
        ->assertDontSee('Wind warning');
});

test('dismissed alert stays hidden on the next page load', function () {
    Setting::set('weather.alerts', [nwsAlert('High Wind Warning', 'Wind warning')]);

    Livewire::test(WeatherAlertBanner::class)->call('dismiss');

    Livewire::test(WeatherAlertBanner::class)->assertDontSee('Wind warning');
});

test('a new alert shows without bringing back the dismissed one', function () {
    $wind = nwsAlert('High Wind Warning', 'Wind warning');
    Setting::set('weather.alerts', [$wind]);

    Livewire::test(WeatherAlertBanner::class)
        ->call('dismiss')
        ->dispatch('echo:weather,WeatherAlertChanged', broadcastAlerts([$wind, nwsAlert('Tornado Warning', 'Tornado incoming', 'red')]))
        ->assertSee('Tornado incoming')
        ->assertDontSee('Wind warning');
});

test('dismissed alert shows again when it moves over the event site', function () {
    Setting::set('weather.alerts', [nwsAlert('Severe Thunderstorm Warning', 'Storm nearby')]);

    Livewire::test(WeatherAlertBanner::class)
        ->call('dismiss')
        ->dispatch('echo:weather,WeatherAlertChanged', broadcastAlerts([nwsAlert('Severe Thunderstorm Warning', 'Storm overhead', 'red')]))
        ->assertSee('Storm overhead');
});

test('alert of a dismissed kind shows again after the earlier one ended', function () {
    Setting::set('weather.alerts', [nwsAlert('Severe Thunderstorm Warning', 'First storm')]);

    Livewire::test(WeatherAlertBanner::class)
        ->call('dismiss')
        ->dispatch('echo:weather,WeatherAlertChanged', broadcastAlerts([]))
        ->dispatch('echo:weather,WeatherAlertChanged', broadcastAlerts([nwsAlert('Severe Thunderstorm Warning', 'Second storm')]))
        ->assertSee('Second storm');
});

test('a new local alert message shows after an earlier one was dismissed', function () {
    $local = fn (string $message): array => ['event' => 'Local Alert', 'headline' => $message, 'description' => $message, 'severity' => 'Severe', 'expires' => null];
    Setting::set('weather.alerts', [$local('Lightning within 10 miles')]);

    Livewire::test(WeatherAlertBanner::class)
        ->call('dismiss')
        ->dispatch('echo:weather,WeatherAlertChanged', broadcastAlerts([$local('Lightning within 5 miles')]))
        ->assertSee('Lightning within 5 miles');
});
