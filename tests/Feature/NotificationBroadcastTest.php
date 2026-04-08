<?php

use App\Enums\NotificationCategory;
use App\Events\NewNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->service = new NotificationService;
});

test('notify dispatches NewNotification broadcast event', function () {
    Event::fake([NewNotification::class]);

    $user = User::factory()->create();

    $this->service->notify(
        user: $user,
        category: NotificationCategory::QsoMilestone,
        title: '50 QSOs!',
        message: 'Milestone reached',
    );

    Event::assertDispatched(NewNotification::class, function ($event) use ($user) {
        return $event->userId === $user->id;
    });
});

test('notify dispatches broadcast event on debounced update', function () {
    $user = User::factory()->create();

    // Create initial notification to trigger debounce on second call
    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section!',
        message: 'W1AW worked CT on 20m SSB',
    );

    Event::fake([NewNotification::class]);

    // Second call within debounce window should update existing and still broadcast
    $this->service->notify(
        user: $user,
        category: NotificationCategory::NewSection,
        title: 'New Section!',
        message: 'W1AW worked NY on 40m CW',
    );

    Event::assertDispatched(NewNotification::class, function ($event) use ($user) {
        return $event->userId === $user->id;
    });
});

test('notify does not dispatch broadcast event for unsubscribed user', function () {
    Event::fake([NewNotification::class]);

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
        message: 'Should not broadcast',
    );

    Event::assertNotDispatched(NewNotification::class);
});

test('notifyAll delivers to all users even when broadcasting throws', function () {
    // Simulate broadcast failure (e.g. Reverb down, sync queue)
    Event::listen(NewNotification::class, function () {
        throw new RuntimeException('Reverb connection refused');
    });

    $users = User::factory()->count(5)->create();

    $this->service->notifyAll(
        category: NotificationCategory::QsoMilestone,
        title: '50 QSOs!',
        message: 'Milestone reached',
    );

    // ALL users should have the notification despite broadcast failure
    foreach ($users as $user) {
        expect($user->fresh()->unreadNotifications()->count())->toBe(1);
    }
});

test('notify stores notification even when broadcasting throws', function () {
    Event::listen(NewNotification::class, function () {
        throw new RuntimeException('Reverb connection refused');
    });

    $user = User::factory()->create();

    $this->service->notify(
        user: $user,
        category: NotificationCategory::QsoMilestone,
        title: '50 QSOs!',
        message: 'Milestone reached',
    );

    expect($user->fresh()->unreadNotifications()->count())->toBe(1);
});

test('NewNotification broadcasts on private user channel', function () {
    $event = new NewNotification(userId: 42);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0]->name)->toBe('private-user.42');
});
