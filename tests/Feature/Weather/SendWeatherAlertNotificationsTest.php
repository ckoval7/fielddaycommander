<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\NotificationCategory;
use App\Events\WeatherAlertChanged;
use App\Listeners\SendWeatherAlertNotifications;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Notifications\WeatherAlertMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);
    Notification::fake();
});

test('sends in-app notification to all users when alerts are active', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Tornado Warning in effect', 'severity' => 'Extreme', 'expires' => null, 'description' => '', 'severity_level' => 'red']],
        hasAlerts: true,
        manual: false,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->category === NotificationCategory::WeatherAlert
            && $notification->title === 'Tornado Warning';
    });
});

test('sets url on in-app notification to the weather page', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Tornado Warning in effect', 'severity' => 'Extreme', 'expires' => null, 'description' => '', 'severity_level' => 'red']],
        hasAlerts: true,
        manual: false,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->url === route('weather.index');
    });
});

test('uses alert headline as message for single alert', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Tornado Warning in effect until 8 PM', 'severity' => 'Extreme', 'expires' => null, 'description' => '', 'severity_level' => 'red']],
        hasAlerts: true,
        manual: false,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->message === 'Tornado Warning in effect until 8 PM';
    });
});

test('uses count title and joined event names for multiple alerts', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [
            ['event' => 'Tornado Warning', 'headline' => 'First', 'severity' => 'Extreme', 'expires' => null, 'description' => '', 'severity_level' => 'red'],
            ['event' => 'Flash Flood Warning', 'headline' => 'Second', 'severity' => 'Moderate', 'expires' => null, 'description' => '', 'severity_level' => 'yellow'],
        ],
        hasAlerts: true,
        manual: false,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->title === '2 Active Weather Alerts'
            && str_contains($notification->message, 'Tornado Warning')
            && str_contains($notification->message, 'Flash Flood Warning');
    });
});

test('sends all-clear in-app notification when alerts are cleared', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [],
        hasAlerts: false,
        manual: false,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->title === 'Weather Alert Lifted'
            && $notification->message === 'The active weather alert has been cleared';
    });
});

test('sends email when alerts active and user has weather_alert_email enabled and mail configured', function () {
    Config::set('mail.email_configured', true);

    $user = User::factory()->create([
        'notification_preferences' => ['weather_alert_email' => true],
    ]);
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Tornado Warning in effect', 'severity' => 'Extreme', 'expires' => null, 'description' => '', 'severity_level' => 'red']],
        hasAlerts: true,
        manual: false,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertSentTo($user, WeatherAlertMail::class);
});

test('does not send email when alerts cleared', function () {
    Config::set('mail.email_configured', true);

    $user = User::factory()->create([
        'notification_preferences' => ['weather_alert_email' => true],
    ]);
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [],
        hasAlerts: false,
        manual: true,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertNotSentTo($user, WeatherAlertMail::class);
});

test('does not send email when mail is not configured', function () {
    Config::set('mail.email_configured', false);

    $user = User::factory()->create([
        'notification_preferences' => ['weather_alert_email' => true],
    ]);
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Test', 'severity' => 'Extreme', 'expires' => null, 'description' => '', 'severity_level' => 'red']],
        hasAlerts: true,
        manual: false,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertNotSentTo($user, WeatherAlertMail::class);
});

test('does not send email when user preference is disabled', function () {
    Config::set('mail.email_configured', true);

    $user = User::factory()->create([
        'notification_preferences' => ['weather_alert_email' => false],
    ]);
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Test', 'severity' => 'Extreme', 'expires' => null, 'description' => '', 'severity_level' => 'red']],
        hasAlerts: true,
        manual: false,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertNotSentTo($user, WeatherAlertMail::class);
});

test('does not send in-app notification to user unsubscribed from weather alerts', function () {
    $user = User::factory()->create([
        'notification_preferences' => [
            'categories' => ['weather_alert' => false],
        ],
    ]);
    $user->assignRole('Operator');

    $event = new WeatherAlertChanged(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Test', 'severity' => 'Extreme', 'expires' => null, 'description' => '', 'severity_level' => 'red']],
        hasAlerts: true,
        manual: false,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertNotSentTo($user, InAppNotification::class);
});

test('announces the local alert headline when it is stacked with NWS alerts', function () {
    $user = User::factory()->create();

    $event = new WeatherAlertChanged(
        alerts: [
            ['event' => 'Local Alert', 'headline' => 'Lightning within 10 miles', 'severity' => 'Severe', 'expires' => null, 'description' => ''],
            ['event' => 'Severe Thunderstorm Warning', 'headline' => 'Warning in effect', 'severity' => 'Severe', 'expires' => null, 'description' => '', 'severity_level' => 'red'],
        ],
        hasAlerts: true,
        manual: true,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->title === 'Local Alert'
            && $notification->message === 'Lightning within 10 miles';
    });
});

test('announces local alert lifted without emailing when NWS alerts remain', function () {
    Config::set('mail.email_configured', true);
    $user = User::factory()->create([
        'notification_preferences' => ['weather_alert_email' => true],
    ]);

    $event = new WeatherAlertChanged(
        alerts: [
            ['event' => 'Severe Thunderstorm Warning', 'headline' => 'Warning in effect', 'severity' => 'Severe', 'expires' => null, 'description' => '', 'severity_level' => 'red'],
        ],
        hasAlerts: true,
        manual: true,
    );

    app(SendWeatherAlertNotifications::class)->handle($event);

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->title === 'Local Alert Lifted'
            && $notification->message === 'The local alert has been cleared. 1 NWS alert still active';
    });
    Notification::assertNotSentTo($user, WeatherAlertMail::class);
});
