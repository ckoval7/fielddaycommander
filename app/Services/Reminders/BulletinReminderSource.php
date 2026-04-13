<?php

namespace App\Services\Reminders;

use App\Contracts\ReminderSource;
use App\Enums\NotificationCategory;
use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class BulletinReminderSource implements ReminderSource
{
    public function getUpcomingRemindables(): Collection
    {
        // Check for active event first, then fall back to setup window
        $event = Event::active()->first()
            ?? Event::inSetupWindow()->first();

        if (! $event) {
            return collect();
        }

        return BulletinScheduleEntry::query()
            ->forEvent($event->id)
            ->inReminderWindow()
            ->get();
    }

    public function getReminderCategory(): NotificationCategory
    {
        return NotificationCategory::BulletinReminder;
    }

    public function buildNotificationData(Model $item, User $user, int $minutes): array
    {
        /** @var BulletinScheduleEntry $item */
        $timeFormatted = $item->scheduled_at->format('Hi').' UTC';
        $minuteLabel = $minutes === 1 ? '1 minute' : "{$minutes} minutes";

        return [
            'title' => "W1AW Bulletin in {$minuteLabel}",
            'message' => "{$item->mode_label} on {$item->frequencies} MHz at {$timeFormatted}",
            'url' => '/w1aw-bulletin',
        ];
    }

    public function buildMailNotification(Model $item, User $user, int $minutes): ?Notification
    {
        return null;
    }

    public function getGroupKey(Model $item, int $minutes): string
    {
        return "bulletin_reminder_{$item->id}_{$minutes}m";
    }

    public function getUsersToNotify(Model $item): Collection
    {
        return User::excludeSystem()->get();
    }

    public function getScheduledTime(Model $item): Carbon
    {
        /** @var BulletinScheduleEntry $item */
        return $item->scheduled_at;
    }

    public function getUserReminderMinutes(User $user): array
    {
        return $user->getBulletinReminderMinutes();
    }

    public function getMinutesPreferenceKey(): string
    {
        return 'bulletin_reminder_minutes';
    }

    public function getEmailPreferenceKey(): ?string
    {
        return null;
    }
}
