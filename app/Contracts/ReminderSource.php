<?php

namespace App\Contracts;

use App\Enums\NotificationCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

interface ReminderSource
{
    /**
     * Get items scheduled within the reminder window (up to 60 min ahead).
     */
    public function getUpcomingRemindables(): Collection;

    /**
     * The notification category for subscription checks.
     */
    public function getReminderCategory(): NotificationCategory;

    /**
     * Build in-app notification data for a specific item, user, and interval.
     *
     * @return array{title: string, message: string, url: string|null}
     */
    public function buildNotificationData(Model $item, User $user, int $minutes): array;

    /**
     * Build an email notification, or null if this source doesn't support email.
     */
    public function buildMailNotification(Model $item, User $user, int $minutes): ?Notification;

    /**
     * Unique deduplication key for this item and interval.
     */
    public function getGroupKey(Model $item, int $minutes): string;

    /**
     * Users who should receive reminders for this item.
     */
    public function getUsersToNotify(Model $item): Collection;

    /**
     * Get the scheduled time for a remindable item.
     */
    public function getScheduledTime(Model $item): \Carbon\Carbon;

    /**
     * Get the reminder minutes for a user from their preferences.
     *
     * @return array<int>
     */
    public function getUserReminderMinutes(User $user): array;

    /**
     * User preferences JSON key for the reminder minutes array.
     */
    public function getMinutesPreferenceKey(): string;

    /**
     * User preferences JSON key for the email toggle, or null if no email support.
     */
    public function getEmailPreferenceKey(): ?string;
}
