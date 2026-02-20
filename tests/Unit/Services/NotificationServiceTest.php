<?php

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->service = new NotificationService;
});

test('notify sends notification to subscribed user', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section!',
        message: 'W1AW worked CT on 20m SSB',
        url: '/contacts',
    );

    Notification::assertSentTo($user, InAppNotification::class, function ($notification) {
        return $notification->title === 'New Section!'
            && $notification->category === NotificationCategory::NewSection;
    });
});

test('notify skips unsubscribed user', function () {
    Notification::fake();

    $user = User::factory()->create([
        'notification_preferences' => [
            'categories' => [
                'new_section' => false,
            ],
        ],
    ]);

    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section!',
        message: 'W1AW worked CT on 20m SSB',
    );

    Notification::assertNotSentTo($user, InAppNotification::class);
});

test('notify defaults to subscribed when no preferences set', function () {
    Notification::fake();

    $user = User::factory()->create([
        'notification_preferences' => null,
    ]);

    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section!',
        message: 'Test message',
    );

    Notification::assertSentTo($user, InAppNotification::class);
});

test('notifyAll sends to all subscribed users', function () {
    Notification::fake();

    $subscribedUser = User::factory()->create();
    $unsubscribedUser = User::factory()->create([
        'notification_preferences' => [
            'categories' => [
                'photos' => false,
            ],
        ],
    ]);

    $this->service->notifyAll(
        category: NotificationCategory::Photos,
        title: 'New Photo',
        message: 'Someone uploaded a photo',
    );

    Notification::assertSentTo($subscribedUser, InAppNotification::class);
    Notification::assertNotSentTo($unsubscribedUser, InAppNotification::class);
});

test('shouldDebounce returns false when no recent notifications exist', function () {
    $user = User::factory()->create();

    $result = $this->service->shouldDebounce($user, 'photo_uploads', 300);

    expect($result)->toBeFalse();
});

test('shouldDebounce returns true when recent notification exists', function () {
    $user = User::factory()->create();

    // Create a notification directly in the database
    $user->notify(new InAppNotification(
        category: NotificationCategory::Photos,
        title: 'Photo Uploaded',
        message: 'Test photo',
        groupKey: 'photo_uploads',
    ));

    $result = $this->service->shouldDebounce($user, 'photo_uploads', 300);

    expect($result)->toBeTrue();
});

test('debounce updates existing notification count and message', function () {
    $user = User::factory()->create();

    // First notification goes through normally
    $this->service->notify(
        user: $user,
        category: NotificationCategory::Photos,
        title: 'New Photo',
        message: 'First photo uploaded',
        groupKey: 'photo_uploads',
    );

    expect($user->notifications)->toHaveCount(1);
    $firstNotification = $user->notifications->first();
    expect($firstNotification->data['count'])->toBe(1);

    // Second notification within debounce window should update existing
    $this->service->notify(
        user: $user,
        category: NotificationCategory::Photos,
        title: 'New Photo',
        message: 'Second photo uploaded',
        groupKey: 'photo_uploads',
    );

    $user->refresh();
    expect($user->notifications)->toHaveCount(1);
    $updatedNotification = $user->notifications->first();
    expect($updatedNotification->data['count'])->toBe(2);
    expect($updatedNotification->data['message'])->toBe('Second photo uploaded');
});

test('debounce marks updated notification as unread', function () {
    $user = User::factory()->create();

    // Create and read a notification
    $this->service->notify(
        user: $user,
        category: NotificationCategory::Photos,
        title: 'New Photo',
        message: 'First photo',
        groupKey: 'photo_uploads',
    );

    $user->notifications->first()->markAsRead();
    $user->refresh();
    expect($user->unreadNotifications)->toHaveCount(0);

    // Debounce should mark it unread again
    $this->service->notify(
        user: $user,
        category: NotificationCategory::Photos,
        title: 'New Photo',
        message: 'Another photo',
        groupKey: 'photo_uploads',
    );

    $user->refresh();
    expect($user->unreadNotifications)->toHaveCount(1);
});

test('zero debounce categories always create new notifications', function () {
    $user = User::factory()->create();

    // QsoMilestone has debounce of 0 — each milestone is unique
    $this->service->notify(
        user: $user,
        category: NotificationCategory::QsoMilestone,
        title: 'QSO Milestone!',
        message: '50 QSOs logged',
        groupKey: 'qso_milestone_50',
    );

    $this->service->notify(
        user: $user,
        category: NotificationCategory::QsoMilestone,
        title: 'QSO Milestone!',
        message: '100 QSOs logged',
        groupKey: 'qso_milestone_100',
    );

    $user->refresh();
    expect($user->notifications)->toHaveCount(2);
});

test('new section notifications debounce within 120 second window', function () {
    $user = User::factory()->create();

    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section Worked!',
        message: 'First section',
        groupKey: 'new_section_event_1',
    );

    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section Worked!',
        message: 'Second section',
        groupKey: 'new_section_event_1',
    );

    $user->refresh();
    expect($user->notifications)->toHaveCount(1);
    expect($user->notifications->first()->data['count'])->toBe(2);
});

test('debounce updates title to reflect count when count exceeds 1', function () {
    $user = User::factory()->create();

    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section Worked!',
        message: 'CT worked',
        groupKey: 'new_section_event_1',
    );

    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section Worked!',
        message: 'NY worked',
        groupKey: 'new_section_event_1',
    );

    $user->refresh();
    expect($user->notifications->first()->data['title'])->toBe('2 New Sections Worked!');

    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section Worked!',
        message: 'WA worked',
        groupKey: 'new_section_event_1',
    );

    $user->refresh();
    expect($user->notifications->first()->data['title'])->toBe('3 New Sections Worked!');
});

test('notification data structure matches contract', function () {
    $user = User::factory()->create();

    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section Worked!',
        message: 'W1AW worked CT on 20m CW',
        url: '/contacts',
        groupKey: 'new_section_event_1',
    );

    $notification = $user->notifications->first();
    $data = $notification->data;

    expect($data)->toHaveKeys(['category', 'group_key', 'title', 'message', 'url', 'count', 'icon']);
    expect($data['category'])->toBe('new_section');
    expect($data['group_key'])->toBe('new_section_event_1');
    expect($data['title'])->toBe('New Section Worked!');
    expect($data['message'])->toBe('W1AW worked CT on 20m CW');
    expect($data['url'])->toBe('/contacts');
    expect($data['count'])->toBe(1);
    expect($data['icon'])->toBe('o-globe-americas');
});
