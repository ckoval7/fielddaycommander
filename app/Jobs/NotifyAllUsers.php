<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Jobs;

use App\Enums\NotificationCategory;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fan a notification out to every subscribed user off the request.
 *
 * Writing one row and queueing one broadcast per member used to happen inside
 * the save that triggered it (a contact, a guestbook entry, a photo), so the
 * operator waited on every member's write.
 */
class NotifyAllUsers implements ShouldQueue
{
    use Queueable;

    /**
     * Not retried: a retry would notify the members a failed run had
     * already reached a second time.
     */
    public int $tries = 1;

    public function __construct(
        public NotificationCategory $category,
        public string $title,
        public string $message,
        public ?string $url = null,
        public ?string $groupKey = null,
    ) {
        $this->afterCommit();
    }

    public function handle(NotificationService $notificationService): void
    {
        $notificationService->sendToAll(
            $this->category,
            $this->title,
            $this->message,
            $this->url,
            $this->groupKey,
        );
    }
}
