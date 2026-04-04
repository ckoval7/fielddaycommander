<?php

use App\Enums\NotificationCategory;
use App\Livewire\Dashboard\Widgets\Feed;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Helper: create a user with optional notification preferences.
 *
 * @param  array<string, mixed>  $preferences
 */
function createFeedUser(array $preferences = []): User
{
    return User::factory()->create([
        'notification_preferences' => $preferences,
    ]);
}

/**
 * Helper: send an InAppNotification to a user.
 */
function sendNotification(
    User $user,
    NotificationCategory $category,
    string $title,
    string $message,
    ?string $groupKey = null,
): void {
    $user->notify(new InAppNotification(
        category: $category,
        title: $title,
        message: $message,
        groupKey: $groupKey,
    ));
}

// ────────────────────────────────────────────────────────────────
// Component Rendering
// ────────────────────────────────────────────────────────────────

test('feed widget renders successfully with default config', function () {
    Livewire::test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'normal',
    ])
        ->assertSuccessful();
});

test('feed widget renders successfully with tv size', function () {
    Livewire::test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'tv',
    ])
        ->assertSuccessful()
        ->assertSet('size', 'tv');
});

test('feed widget uses IsWidget trait properties', function () {
    $config = ['feed_type' => 'milestones_only'];

    Livewire::test(Feed::class, [
        'config' => $config,
        'size' => 'normal',
        'widgetId' => 'test-feed-123',
    ])
        ->assertSet('config', $config)
        ->assertSet('size', 'normal')
        ->assertSet('widgetId', 'test-feed-123');
});

// ────────────────────────────────────────────────────────────────
// getData() return structure
// ────────────────────────────────────────────────────────────────

test('getData returns expected structure with no notifications', function () {
    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data)
        ->toBeArray()
        ->toHaveKeys(['items', 'feed_type', 'feed_label'])
        ->and($data['items'])->toBeArray()->toBeEmpty()
        ->and($data['feed_type'])->toBe('all_activity')
        ->and($data['feed_label'])->toBe('All Activity');
});

test('getData returns correct feed label for each feed type', function (string $feedType, string $expectedLabel) {
    $feed = new Feed;
    $feed->config = ['feed_type' => $feedType];
    $feed->widgetId = "test-feed-{$feedType}";
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['feed_label'])->toBe($expectedLabel);
})->with([
    ['all_activity', 'All Activity'],
    ['contacts_only', 'Contacts'],
    ['milestones_only', 'Milestones'],
    ['equipment_events', 'Equipment'],
]);

test('getData defaults to all_activity for invalid feed type', function () {
    $feed = new Feed;
    $feed->config = ['feed_type' => 'invalid_type'];
    $feed->widgetId = 'test-feed-invalid';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['feed_type'])->toBe('all_activity');
});

test('getData defaults to all_activity when feed_type is missing from config', function () {
    $feed = new Feed;
    $feed->config = [];
    $feed->widgetId = 'test-feed-missing';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['feed_type'])->toBe('all_activity');
});

// ────────────────────────────────────────────────────────────────
// Feed item structure
// ────────────────────────────────────────────────────────────────

test('feed items have expected structure', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone!', '50 QSOs logged!');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-structure';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0])->toHaveKeys(['id', 'icon', 'title', 'message', 'time_ago', 'read'])
        ->and($data['items'][0]['title'])->toBe('Milestone!')
        ->and($data['items'][0]['message'])->toBe('50 QSOs logged!')
        ->and($data['items'][0]['icon'])->toBe('o-trophy')
        ->and($data['items'][0]['read'])->toBeFalse();
});

test('read notifications are marked as read in feed items', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone', '100 QSOs!');

    $user->notifications()->first()->markAsRead();

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-read';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'][0]['read'])->toBeTrue();
});

// ────────────────────────────────────────────────────────────────
// Feed type filtering: all_activity
// ────────────────────────────────────────────────────────────────

test('all_activity feed shows all notification categories', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone', '50 QSOs');
    sendNotification($user, NotificationCategory::Equipment, 'Equipment', 'Radio online');
    sendNotification($user, NotificationCategory::NewSection, 'New Section', 'Worked NNY');
    sendNotification($user, NotificationCategory::Guestbook, 'Guestbook', 'New entry');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-all';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(4);
});

// ────────────────────────────────────────────────────────────────
// Feed type filtering: contacts_only
// ────────────────────────────────────────────────────────────────

test('contacts_only feed shows only qso_milestone and new_section categories', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone', '50 QSOs');
    sendNotification($user, NotificationCategory::NewSection, 'New Section', 'Worked NNY');
    sendNotification($user, NotificationCategory::Equipment, 'Equipment', 'Radio online');
    sendNotification($user, NotificationCategory::Guestbook, 'Guestbook', 'New entry');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'contacts_only'];
    $feed->widgetId = 'test-feed-contacts';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(2);

    $titles = array_column($data['items'], 'title');
    expect($titles)->toContain('Milestone')
        ->and($titles)->toContain('New Section')
        ->and($titles)->not->toContain('Equipment')
        ->and($titles)->not->toContain('Guestbook');
});

// ────────────────────────────────────────────────────────────────
// Feed type filtering: milestones_only
// ────────────────────────────────────────────────────────────────

test('milestones_only feed shows only qso_milestone category', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone', '50 QSOs');
    sendNotification($user, NotificationCategory::NewSection, 'New Section', 'Worked NNY');
    sendNotification($user, NotificationCategory::Equipment, 'Equipment', 'Radio online');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'milestones_only'];
    $feed->widgetId = 'test-feed-milestones';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['title'])->toBe('Milestone');
});

// ────────────────────────────────────────────────────────────────
// Feed type filtering: equipment_events
// ────────────────────────────────────────────────────────────────

test('equipment_events feed shows only equipment category', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone', '50 QSOs');
    sendNotification($user, NotificationCategory::Equipment, 'Equipment Change', 'Radio online');
    sendNotification($user, NotificationCategory::Equipment, 'Equipment Alert', 'Antenna issue');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'equipment_events'];
    $feed->widgetId = 'test-feed-equipment';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(2);

    $titles = array_column($data['items'], 'title');
    expect($titles)->toContain('Equipment Change')
        ->and($titles)->toContain('Equipment Alert')
        ->and($titles)->not->toContain('Milestone');
});

// ────────────────────────────────────────────────────────────────
// Chronological ordering
// ────────────────────────────────────────────────────────────────

test('feed items are ordered newest first', function () {
    $user = createFeedUser();

    sendNotification($user, NotificationCategory::QsoMilestone, 'First', 'Oldest item');

    $this->travel(5)->minutes();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Second', 'Middle item');

    $this->travel(5)->minutes();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Third', 'Newest item');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-order';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(3)
        ->and($data['items'][0]['title'])->toBe('Third')
        ->and($data['items'][1]['title'])->toBe('Second')
        ->and($data['items'][2]['title'])->toBe('First');
});

// ────────────────────────────────────────────────────────────────
// Item count limits
// ────────────────────────────────────────────────────────────────

test('normal mode limits to 20 items', function () {
    $user = createFeedUser();

    for ($i = 1; $i <= 25; $i++) {
        sendNotification($user, NotificationCategory::QsoMilestone, "Item {$i}", "Message {$i}");
    }

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-limit-normal';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(20);
});

test('tv mode limits to 15 items', function () {
    $user = createFeedUser();

    for ($i = 1; $i <= 25; $i++) {
        sendNotification($user, NotificationCategory::QsoMilestone, "Item {$i}", "Message {$i}");
    }

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-limit-tv';
    $feed->size = 'tv';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(15);
});

// ────────────────────────────────────────────────────────────────
// User notification preferences
// ────────────────────────────────────────────────────────────────

test('respects user preference to disable a category', function () {
    $user = createFeedUser([
        'categories' => [
            'equipment' => false,
        ],
    ]);

    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone', '50 QSOs');
    sendNotification($user, NotificationCategory::Equipment, 'Equipment', 'Radio online');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-prefs';
    $feed->size = 'normal';

    $data = $feed->getData();

    $titles = array_column($data['items'], 'title');
    expect($titles)->toContain('Milestone')
        ->and($titles)->not->toContain('Equipment');
});

test('categories default to enabled when not configured', function () {
    $user = createFeedUser([]);
    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone', '50 QSOs');
    sendNotification($user, NotificationCategory::Equipment, 'Equipment', 'Radio online');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-defaults';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(2);
});

test('unauthenticated user sees empty feed', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone', '50 QSOs');
    sendNotification($user, NotificationCategory::Equipment, 'Equipment', 'Radio online');

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-guest';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toBeEmpty();
});

test('user preferences interact correctly with feed type filter', function () {
    $user = createFeedUser([
        'categories' => [
            'qso_milestone' => false,
        ],
    ]);

    sendNotification($user, NotificationCategory::QsoMilestone, 'Milestone', '50 QSOs');
    sendNotification($user, NotificationCategory::NewSection, 'Section', 'Worked NNY');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'contacts_only'];
    $feed->widgetId = 'test-feed-prefs-type';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['title'])->toBe('Section');
});

test('returns empty when all feed type categories are disabled by user', function () {
    $user = createFeedUser([
        'categories' => [
            'equipment' => false,
        ],
    ]);

    sendNotification($user, NotificationCategory::Equipment, 'Equipment', 'Radio online');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'equipment_events'];
    $feed->widgetId = 'test-feed-all-disabled';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'])->toBeEmpty();
});

// ────────────────────────────────────────────────────────────────
// Icons from notification category
// ────────────────────────────────────────────────────────────────

test('feed items use icon from notification category', function (NotificationCategory $category, string $expectedIcon) {
    $user = createFeedUser();
    sendNotification($user, $category, 'Test', 'Test message');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-icon';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'][0]['icon'])->toBe($expectedIcon);
})->with([
    [NotificationCategory::QsoMilestone, 'o-trophy'],
    [NotificationCategory::NewSection, 'o-globe-americas'],
    [NotificationCategory::Equipment, 'o-wrench-screwdriver'],
    [NotificationCategory::Guestbook, 'o-book-open'],
    [NotificationCategory::Photos, 'o-photo'],
    [NotificationCategory::StationStatus, 'o-signal'],
]);

// ────────────────────────────────────────────────────────────────
// Time ago formatting
// ────────────────────────────────────────────────────────────────

test('time_ago shows just now for recent notifications', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Test', 'Message');

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-time-now';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'][0]['time_ago'])->toBe('just now');
});

test('time_ago shows minutes for notifications less than an hour old', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Test', 'Message');

    $this->travel(5)->minutes();

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-time-min';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'][0]['time_ago'])->toBe('5m ago');
});

test('time_ago shows hours for notifications less than a day old', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Test', 'Message');

    $this->travel(3)->hours();

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-time-hr';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'][0]['time_ago'])->toBe('3h ago');
});

test('time_ago shows days for older notifications', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Test', 'Message');

    $this->travel(2)->days();

    $this->actingAs($user);

    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-time-day';
    $feed->size = 'normal';

    $data = $feed->getData();

    expect($data['items'][0]['time_ago'])->toBe('2d ago');
});

// ────────────────────────────────────────────────────────────────
// Caching
// ────────────────────────────────────────────────────────────────

test('getData caches results', function () {
    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-cache';
    $feed->size = 'normal';

    $cacheKey = $feed->cacheKey();
    Cache::forget($cacheKey);

    expect(Cache::has($cacheKey))->toBeFalse();

    $feed->getData();

    expect(Cache::has($cacheKey))->toBeTrue();
});

test('cached data is returned on subsequent calls', function () {
    $feed = new Feed;
    $feed->config = ['feed_type' => 'all_activity'];
    $feed->widgetId = 'test-feed-cache-hit';
    $feed->size = 'normal';

    $cacheKey = $feed->cacheKey();
    Cache::forget($cacheKey);

    $data1 = $feed->getData();
    $data2 = $feed->getData();

    expect($data1)->toBe($data2);
});

// ────────────────────────────────────────────────────────────────
// Widget listeners
// ────────────────────────────────────────────────────────────────

test('getWidgetListeners returns correct listeners', function () {
    $feed = new Feed;

    expect($feed->getWidgetListeners())->toBe([
        'echo:notifications,DatabaseNotificationCreated' => 'handleUpdate',
        'notification.created' => 'handleUpdate',
    ]);
});

// ────────────────────────────────────────────────────────────────
// Empty state
// ────────────────────────────────────────────────────────────────

test('shows empty state when no notifications exist', function () {
    Livewire::test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'normal',
    ])
        ->assertSee('No activity yet');
});

test('shows empty state in tv mode', function () {
    Livewire::test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'tv',
    ])
        ->assertSee('No activity yet');
});

// ────────────────────────────────────────────────────────────────
// View rendering
// ────────────────────────────────────────────────────────────────

test('normal mode displays feed label', function () {
    Livewire::test(Feed::class, [
        'config' => ['feed_type' => 'milestones_only'],
        'size' => 'normal',
    ])
        ->assertSee('Milestones');
});

test('tv mode displays feed label', function () {
    Livewire::test(Feed::class, [
        'config' => ['feed_type' => 'equipment_events'],
        'size' => 'tv',
    ])
        ->assertSee('Equipment');
});

test('normal mode shows notification title and message', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, '100 QSO Milestone', 'Congratulations! 100 QSOs logged.');

    Livewire::actingAs($user)->test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'normal',
    ])
        ->assertSee('100 QSO Milestone')
        ->assertSee('Congratulations! 100 QSOs logged.');
});

test('tv mode shows notification title and message', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::Equipment, 'Antenna Ready', 'The beam antenna is set up.');

    Livewire::actingAs($user)->test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'tv',
    ])
        ->assertSee('Antenna Ready')
        ->assertSee('The beam antenna is set up.');
});

test('normal mode has scrollable container', function () {
    Livewire::test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'normal',
    ])
        ->assertSeeHtml('max-h-[350px]');
});

test('tv mode has scrollable container', function () {
    Livewire::test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'tv',
    ])
        ->assertSeeHtml('max-h-[500px]');
});

test('view uses wire:key for feed items', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Test', 'Message');

    $notificationId = $user->notifications()->first()->id;

    Livewire::actingAs($user)->test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'normal',
    ])
        ->assertSeeHtml("wire:key=\"feed-item-{$notificationId}\"");
});

test('item count badge is shown in normal mode', function () {
    $user = createFeedUser();
    sendNotification($user, NotificationCategory::QsoMilestone, 'Test', 'Message');

    Livewire::actingAs($user)->test(Feed::class, [
        'config' => ['feed_type' => 'all_activity'],
        'size' => 'normal',
    ])
        ->assertSeeHtml('1');
});
