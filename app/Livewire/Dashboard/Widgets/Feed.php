<?php

namespace App\Livewire\Dashboard\Widgets;

use App\Enums\NotificationCategory;
use App\Livewire\Dashboard\Widgets\Concerns\IsWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Feed Widget - Live activity stream from notifications.
 *
 * Displays a scrollable list of recent notifications filtered by feed type
 * and user subscription preferences. Supports four feed types:
 *
 * - all_activity: All notification categories
 * - contacts_only: QSO milestone and new section notifications
 * - milestones_only: QSO milestone notifications only
 * - equipment_events: Equipment-related notifications only
 *
 * Config structure:
 * [
 *   'feed_type' => 'all_activity|contacts_only|milestones_only|equipment_events'
 * ]
 */
class Feed extends Component
{
    use IsWidget;

    /**
     * Supported feed types and their associated notification categories.
     *
     * @var array<string, array<string>>
     */
    protected const FEED_TYPE_CATEGORIES = [
        'all_activity' => [],
        'contacts_only' => ['qso_milestone', 'new_section'],
        'milestones_only' => ['qso_milestone'],
        'equipment_events' => ['equipment'],
    ];

    /**
     * Cache duration in seconds.
     */
    protected const CACHE_TTL = 5;

    /**
     * Number of items to display per size variant.
     *
     * @var array<string, int>
     */
    protected const ITEM_COUNTS = [
        'normal' => 20,
        'tv' => 15,
    ];

    /**
     * Feed type labels for display.
     *
     * @var array<string, string>
     */
    protected const FEED_TYPE_LABELS = [
        'all_activity' => 'All Activity',
        'contacts_only' => 'Contacts',
        'milestones_only' => 'Milestones',
        'equipment_events' => 'Equipment',
    ];

    /**
     * Fetch feed items from the notifications table.
     *
     * Returns an array with feed items, feed type label, and item count.
     *
     * @return array{items: array<int, array{id: string, icon: string, title: string, message: string, time_ago: string, read: bool}>, feed_type: string, feed_label: string}
     */
    public function getData(): array
    {
        return Cache::remember($this->cacheKey(), self::CACHE_TTL, function () {
            $feedType = $this->getFeedType();
            $items = $this->queryNotifications($feedType);

            return [
                'items' => $items,
                'feed_type' => $feedType,
                'feed_label' => self::FEED_TYPE_LABELS[$feedType] ?? 'Activity',
                'last_updated_at' => appNow(),
            ];
        });
    }

    /**
     * Define Livewire event listeners for this widget.
     *
     * Listens to notification broadcast events for immediate updates.
     * All notification types trigger a refresh to show new activity.
     *
     * @return array<string, string>
     */
    public function getWidgetListeners(): array
    {
        return [
            'echo:notifications,DatabaseNotificationCreated' => 'handleUpdate',
            'notification.created' => 'handleUpdate',
        ];
    }

    /**
     * Render the feed widget view.
     */
    public function render(): View
    {
        return view('livewire.dashboard.widgets.feed', [
            'data' => $this->getData(),
        ]);
    }

    /**
     * Get the validated feed type from config.
     */
    protected function getFeedType(): string
    {
        $feedType = $this->config['feed_type'] ?? 'all_activity';

        if (! array_key_exists($feedType, self::FEED_TYPE_CATEGORIES)) {
            return 'all_activity';
        }

        return $feedType;
    }

    /**
     * Query notifications filtered by feed type and user preferences.
     *
     * @return array<int, array{id: string, icon: string, title: string, message: string, time_ago: string, read: bool}>
     */
    protected function queryNotifications(string $feedType): array
    {
        $allowedCategories = $this->getAllowedCategories($feedType);

        if ($allowedCategories === []) {
            return [];
        }

        $limit = self::ITEM_COUNTS[$this->size] ?? self::ITEM_COUNTS['normal'];

        $user = Auth::user();

        if (! $user) {
            return [];
        }

        $query = $user->notifications()
            ->latest()
            ->limit($limit);

        $this->applyCategoryFilter($query, $allowedCategories);

        return $query->get()
            ->map(fn (DatabaseNotification $notification) => $this->formatNotification($notification))
            ->toArray();
    }

    /**
     * Get the allowed categories based on feed type and user preferences.
     *
     * Returns the intersection of feed type categories and user-subscribed categories.
     * For unauthenticated users, all feed type categories are allowed.
     *
     * @return array<int, string>
     */
    protected function getAllowedCategories(string $feedType): array
    {
        $feedCategories = self::FEED_TYPE_CATEGORIES[$feedType] ?? [];

        if ($feedCategories === []) {
            $feedCategories = array_map(
                fn (NotificationCategory $category) => $category->value,
                NotificationCategory::cases()
            );
        }

        $user = Auth::user();

        if (! $user) {
            return $feedCategories;
        }

        return array_values(array_filter(
            $feedCategories,
            function (string $categoryValue) use ($user) {
                $category = NotificationCategory::tryFrom($categoryValue);

                if (! $category) {
                    return false;
                }

                return $user->isSubscribedTo($category);
            }
        ));
    }

    /**
     * Apply category filter to the notification query.
     */
    protected function applyCategoryFilter(Builder|MorphMany $query, array $categories): void
    {
        $query->where(function (Builder $q) use ($categories) {
            foreach ($categories as $category) {
                $q->orWhere('data->category', $category);
            }
        });
    }

    /**
     * Format a notification into a feed item array.
     *
     * @return array{id: string, icon: string, title: string, message: string, time_ago: string, read: bool}
     */
    protected function formatNotification(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $categoryValue = $data['category'] ?? null;
        $category = $categoryValue ? NotificationCategory::tryFrom($categoryValue) : null;

        return [
            'id' => $notification->id,
            'icon' => $data['icon'] ?? $category?->icon() ?? 'o-bell',
            'title' => $data['title'] ?? 'Notification',
            'message' => $data['message'] ?? '',
            'time_ago' => $this->formatTimeAgo($notification->created_at),
            'read' => $notification->read_at !== null,
        ];
    }

    /**
     * Format a Carbon timestamp into a human-readable relative time string.
     */
    protected function formatTimeAgo(?Carbon $timestamp): string
    {
        if (! $timestamp) {
            return '';
        }

        $diffInSeconds = $timestamp->diffInSeconds(appNow());

        return match (true) {
            $diffInSeconds < 60 => 'just now',
            $diffInSeconds < 3600 => (int) floor($diffInSeconds / 60).'m ago',
            $diffInSeconds < 86400 => (int) floor($diffInSeconds / 3600).'h ago',
            default => (int) floor($diffInSeconds / 86400).'d ago',
        };
    }
}
