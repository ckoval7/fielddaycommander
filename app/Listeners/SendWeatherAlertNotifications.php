<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Listeners;

use App\Enums\NotificationCategory;
use App\Events\WeatherAlertChanged;
use App\Models\User;
use App\Notifications\WeatherAlertMail;
use App\Services\NotificationService;

class SendWeatherAlertNotifications
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(WeatherAlertChanged $event): void
    {
        [$title, $message] = $this->buildMessage($event);
        $shouldSendEmail = $event->hasAlerts
            && ! $this->isLocalAlertLifted($event)
            && config('mail.email_configured');

        User::all()->each(function (User $user) use ($event, $title, $message, $shouldSendEmail) {
            $this->notificationService->notify(
                user: $user,
                category: NotificationCategory::WeatherAlert,
                title: $title,
                message: $message,
                url: route('weather.index'),
            );

            if ($shouldSendEmail && ($user->notification_preferences['weather_alert_email'] ?? false)) {
                $user->notify(new WeatherAlertMail($event->alerts, $title));
            }
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function buildMessage(WeatherAlertChanged $event): array
    {
        $count = count($event->alerts);
        $localAlert = collect($event->alerts)->firstWhere('event', 'Local Alert');

        return match (true) {
            ! $event->hasAlerts => ['Weather Alert Lifted', 'The active weather alert has been cleared'],
            $this->isLocalAlertLifted($event) => [
                'Local Alert Lifted',
                "The local alert has been cleared. {$count} NWS ".str('alert')->plural($count).' still active',
            ],
            $event->manual && $localAlert !== null => [$localAlert['event'], $localAlert['headline']],
            $count === 1 => [$event->alerts[0]['event'], $event->alerts[0]['headline']],
            default => ["{$count} Active Weather Alerts", implode(', ', array_column($event->alerts, 'event'))],
        };
    }

    /**
     * A manual change that leaves only NWS alerts behind means the local alert was cleared.
     */
    private function isLocalAlertLifted(WeatherAlertChanged $event): bool
    {
        return $event->manual
            && $event->hasAlerts
            && ! collect($event->alerts)->contains(fn (array $alert): bool => ($alert['event'] ?? '') === 'Local Alert');
    }
}
