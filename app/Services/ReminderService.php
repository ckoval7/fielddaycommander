<?php

namespace App\Services;

use App\Contracts\ReminderSource;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ReminderService
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * Process a reminder source: find upcoming items, check user preferences, send notifications.
     */
    public function processSource(ReminderSource $source): int
    {
        $items = $source->getUpcomingRemindables();

        if ($items->isEmpty()) {
            return 0;
        }

        $sentCount = 0;
        $windowStart = appNow()->subMinutes(5);
        $windowEnd = appNow();

        foreach ($items as $item) {
            $users = $source->getUsersToNotify($item);

            foreach ($users as $user) {
                $sentCount += $this->processUserReminders(
                    $source, $user, $item, $windowStart, $windowEnd
                );
            }
        }

        return $sentCount;
    }

    /**
     * Send due reminders for a specific user and remindable item.
     */
    private function processUserReminders(
        ReminderSource $source,
        User $user,
        Model $item,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): int {
        $sentCount = 0;
        $minutes = $source->getUserReminderMinutes($user);
        $scheduledAt = $source->getScheduledTime($item);

        foreach ($minutes as $minutesBefore) {
            $reminderTime = $scheduledAt->copy()->subMinutes($minutesBefore);

            if ($reminderTime->lt($windowStart) || $reminderTime->gt($windowEnd)) {
                continue;
            }

            $groupKey = $source->getGroupKey($item, $minutesBefore);

            if ($user->notifications()->where('data->group_key', $groupKey)->exists()) {
                continue;
            }

            $data = $source->buildNotificationData($item, $user, $minutesBefore);

            $this->notificationService->notify(
                user: $user,
                category: $source->getReminderCategory(),
                title: $data['title'],
                message: $data['message'],
                url: $data['url'] ?? null,
                groupKey: $groupKey,
            );

            $this->sendEmailIfEnabled($source, $item, $user, $minutesBefore);

            $sentCount++;
        }

        return $sentCount;
    }

    /**
     * Send email notification if the source supports it and the user has it enabled.
     */
    private function sendEmailIfEnabled(
        ReminderSource $source,
        Model $item,
        User $user,
        int $minutes,
    ): void {
        if (! config('mail.email_configured')) {
            return;
        }

        $emailKey = $source->getEmailPreferenceKey();

        if ($emailKey === null) {
            return;
        }

        $emailEnabled = $user->notification_preferences[$emailKey] ?? false;

        if (! $emailEnabled) {
            return;
        }

        $mailNotification = $source->buildMailNotification($item, $user, $minutes);

        if ($mailNotification !== null) {
            $user->notify($mailNotification);
        }
    }
}
