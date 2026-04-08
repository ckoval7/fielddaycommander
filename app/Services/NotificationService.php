<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Events\NewNotification;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send a notification to a specific user, respecting debounce and subscription preferences.
     */
    public function notify(
        User $user,
        NotificationCategory $category,
        string $title,
        string $message,
        ?string $url = null,
        ?string $groupKey = null,
    ): void {
        if (! $user->isSubscribedTo($category)) {
            return;
        }

        $debounceSeconds = $category->debounceSeconds();
        $effectiveGroupKey = $groupKey ?? $category->value;

        if ($debounceSeconds > 0 && $this->shouldDebounce($user, $effectiveGroupKey, $debounceSeconds)) {
            $this->updateExistingNotification($user, $effectiveGroupKey, $message, $category);
            $this->broadcastSafely($user->id);

            return;
        }

        $user->notify(new InAppNotification(
            category: $category,
            title: $title,
            message: $message,
            url: $url,
            groupKey: $effectiveGroupKey,
        ));

        $this->broadcastSafely($user->id);
    }

    /**
     * Send a notification to all users subscribed to the given category.
     */
    public function notifyAll(
        NotificationCategory $category,
        string $title,
        string $message,
        ?string $url = null,
        ?string $groupKey = null,
    ): void {
        $users = User::all();

        foreach ($users as $user) {
            $this->notify($user, $category, $title, $message, $url, $groupKey);
        }
    }

    /**
     * Check if a notification with the same group_key exists within the debounce window.
     */
    public function shouldDebounce(User $user, string $groupKey, int $windowSeconds): bool
    {
        $cutoff = now()->subSeconds($windowSeconds);

        return $user->notifications()
            ->where('data->group_key', $groupKey)
            ->where('created_at', '>=', $cutoff)
            ->exists();
    }

    /**
     * Update an existing notification's count and message instead of creating a new one.
     */
    protected function updateExistingNotification(User $user, string $groupKey, string $message, NotificationCategory $category): void
    {
        $notification = $user->notifications()
            ->where('data->group_key', $groupKey)
            ->latest()
            ->first();

        if (! $notification) {
            return;
        }

        $data = $notification->data;
        $count = ($data['count'] ?? 1) + 1;
        $data['count'] = $count;
        $data['message'] = $message;

        $batchedTitle = $category->batchedTitle($count);
        if ($batchedTitle !== null) {
            $data['title'] = $batchedTitle;
        }

        $notification->data = $data;
        $notification->read_at = null;
        $notification->save();
    }

    /**
     * Broadcast the notification event, swallowing failures so they never
     * prevent database-stored notifications from being delivered.
     */
    protected function broadcastSafely(int $userId): void
    {
        try {
            NewNotification::dispatch($userId);
        } catch (\Throwable $e) {
            Log::warning('Failed to broadcast notification', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
