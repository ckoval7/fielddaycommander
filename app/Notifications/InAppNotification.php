<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Notifications;

use App\Enums\NotificationCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InAppNotification extends Notification
{
    use Queueable;

    public function __construct(
        public NotificationCategory $category,
        public string $title,
        public string $message,
        public ?string $url = null,
        public ?string $groupKey = null,
        public int $count = 1,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category->value,
            'group_key' => $this->groupKey,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'count' => $this->count,
            'icon' => $this->category->icon(),
        ];
    }
}
