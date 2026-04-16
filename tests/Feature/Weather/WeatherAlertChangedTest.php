<?php

use App\Events\WeatherAlertChanged;
use Illuminate\Broadcasting\Channel;

test('it broadcasts on the public weather channel', function () {
    $event = new WeatherAlertChanged(
        alerts: [['event' => 'Tornado Warning', 'headline' => 'Tornado Warning issued']],
        hasAlerts: true,
        manual: false,
    );

    expect($event->broadcastOn())->toBeInstanceOf(Channel::class);
    expect($event->broadcastOn()->name)->toBe('weather');
});

test('broadcastWith includes all required fields', function () {
    $alerts = [['event' => 'Severe Thunderstorm Warning', 'headline' => 'Test headline']];

    $event = new WeatherAlertChanged(
        alerts: $alerts,
        hasAlerts: true,
        manual: false,
    );

    $payload = $event->broadcastWith();

    expect($payload)->toHaveKeys(['alerts', 'has_alerts', 'manual']);
    expect($payload['alerts'])->toBe($alerts);
    expect($payload['has_alerts'])->toBeTrue();
    expect($payload['manual'])->toBeFalse();
});

test('manual flag is included when manually triggered', function () {
    $event = new WeatherAlertChanged(alerts: [], hasAlerts: false, manual: true);

    expect($event->broadcastWith()['manual'])->toBeTrue();
});
