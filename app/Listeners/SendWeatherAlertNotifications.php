<?php

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
        $shouldSendEmail = $event->hasAlerts && config('mail.email_configured');

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
        if (! $event->hasAlerts) {
            return ['Weather Alert Lifted', 'The active weather alert has been cleared'];
        }

        $count = count($event->alerts);

        if ($count === 1) {
            return [
                $event->alerts[0]['event'],
                $event->alerts[0]['headline'],
            ];
        }

        $eventNames = implode(', ', array_column($event->alerts, 'event'));

        return ["{$count} Active Weather Alerts", $eventNames];
    }
}
