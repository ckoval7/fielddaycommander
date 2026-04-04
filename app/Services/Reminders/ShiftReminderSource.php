<?php

namespace App\Services\Reminders;

use App\Contracts\ReminderSource;
use App\Enums\NotificationCategory;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Notifications\ShiftCheckinReminderMail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class ShiftReminderSource implements ReminderSource
{
    public function getUpcomingRemindables(): Collection
    {
        $activeEvent = Event::active()->first();

        if (! $activeEvent || ! $activeEvent->eventConfiguration) {
            return collect();
        }

        return Shift::query()
            ->forEvent($activeEvent->eventConfiguration->id)
            ->with('shiftRole')
            ->whereBetween('start_time', [appNow()->subMinutes(5), appNow()->addMinutes(60)])
            ->get();
    }

    public function getReminderCategory(): NotificationCategory
    {
        return NotificationCategory::ShiftCheckinReminder;
    }

    public function buildNotificationData(Model $item, User $user, int $minutes): array
    {
        /** @var Shift $item */
        $roleName = $item->shiftRole->name;
        $minuteLabel = $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        $timeRange = $item->start_time->format('H:i').'–'.$item->end_time->format('H:i').' UTC';

        return [
            'title' => "Shift in {$minuteLabel}: {$roleName}",
            'message' => "{$roleName} shift from {$timeRange}. Don't forget to check in!",
            'url' => '/schedule/my-shifts',
        ];
    }

    public function buildMailNotification(Model $item, User $user, int $minutes): ?Notification
    {
        /** @var Shift $item */
        return new ShiftCheckinReminderMail($item, $minutes);
    }

    public function getGroupKey(Model $item, int $minutes): string
    {
        return "shift_reminder_{$item->id}_{$minutes}m";
    }

    public function getUsersToNotify(Model $item): Collection
    {
        /** @var Shift $item */
        return User::query()
            ->whereHas('shiftAssignments', function ($query) use ($item) {
                $query->where('shift_id', $item->id)
                    ->where('status', ShiftAssignment::STATUS_SCHEDULED);
            })
            ->get();
    }

    public function getScheduledTime(Model $item): \Carbon\Carbon
    {
        /** @var Shift $item */
        return $item->start_time;
    }

    public function getUserReminderMinutes(User $user): array
    {
        return $user->getShiftReminderMinutes();
    }

    public function getMinutesPreferenceKey(): string
    {
        return 'shift_reminder_minutes';
    }

    public function getEmailPreferenceKey(): ?string
    {
        return 'shift_reminder_email';
    }
}
