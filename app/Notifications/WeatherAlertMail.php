<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeatherAlertMail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{event: string, headline: string}>  $alerts
     */
    public function __construct(
        public readonly array $alerts,
        public readonly string $title,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Weather Alert: {$this->title}")
            ->greeting("Hello {$notifiable->first_name},")
            ->line('A weather alert is active for your event location.');

        foreach ($this->alerts as $alert) {
            $mail->line("**{$alert['event']}**")
                ->line($alert['headline']);
        }

        return $mail->line('Please take appropriate safety precautions.');
    }
}
