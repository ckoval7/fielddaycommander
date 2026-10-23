<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitation extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $token,
        public string $adminName,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $invitationUrl = url("/register/invite/{$this->token}");

        return (new MailMessage)
            ->subject("You've been invited to Field Day Commander")
            ->greeting("Hello {$notifiable->first_name},")
            ->line("{$this->adminName} has invited you to join Field Day Commander.")
            ->line('Your account details:')
            ->line("Call Sign: {$notifiable->call_sign}")
            ->line("Email: {$notifiable->email}")
            ->action('Accept Invitation', $invitationUrl)
            ->line('This invitation will expire in 72 hours.')
            ->line("If you didn't expect this invitation, you can ignore this email.");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'token' => $this->token,
            'admin_name' => $this->adminName,
        ];
    }
}
