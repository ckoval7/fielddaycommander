<?php

namespace App\Services\Reminders;

use App\Contracts\ReminderSource;
use App\Enums\NotificationCategory;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Notifications\ShiftCheckoutReminderMail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class ShiftCheckoutReminderSource implements ReminderSource
{
    public function getUpcomingRemindables(): Collection
    {
        return ShiftAssignment::query()
            ->where('status', ShiftAssignment::STATUS_CHECKED_IN)
            ->whereNull('checked_out_at')
            ->whereHas('shift', function ($query) {
                $query->whereBetween('end_time', [
                    appNow()->subMinutes(35),
                    appNow()->subMinutes(30),
                ]);
            })
            ->with('shift.shiftRole')
            ->get();
    }

    public function getReminderCategory(): NotificationCategory
    {
        return NotificationCategory::ShiftCheckoutReminder;
    }

    public function buildNotificationData(Model $item, User $user, int $minutes): array
    {
        /** @var ShiftAssignment $item */
        $shift = $item->shift;
        $roleName = $shift->shiftRole->name;
        $endTime = $shift->end_time->format('H:i').' UTC';

        return [
            'title' => "Forgot to check out? {$roleName}",
            'message' => "Your {$roleName} shift ended at {$endTime}. Don't forget to check out.",
            'url' => '/schedule/my-shifts',
        ];
    }

    public function buildMailNotification(Model $item, User $user, int $minutes): ?Notification
    {
        /** @var ShiftAssignment $item */
        return new ShiftCheckoutReminderMail($item->shift);
    }

    public function getGroupKey(Model $item, int $minutes): string
    {
        /** @var ShiftAssignment $item */
        return "shift_checkout_reminder_{$item->id}";
    }

    public function getUsersToNotify(Model $item): Collection
    {
        /** @var ShiftAssignment $item */
        return User::query()->whereKey($item->user_id)->get();
    }

    public function getScheduledTime(Model $item): Carbon
    {
        /** @var ShiftAssignment $item */
        return $item->shift->end_time->copy()->addMinutes(30);
    }

    public function getUserReminderMinutes(User $user): array
    {
        return [0];
    }

    public function getMinutesPreferenceKey(): string
    {
        return 'shift_checkout_reminder_minutes';
    }

    public function getEmailPreferenceKey(): ?string
    {
        return 'shift_reminder_email';
    }
}
