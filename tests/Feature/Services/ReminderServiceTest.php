<?php

use App\Contracts\ReminderSource;
use App\Enums\NotificationCategory;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ReminderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([\Database\Seeders\EventTypeSeeder::class, \Database\Seeders\BonusTypeSeeder::class]);
    $this->user = User::factory()->create();
});

function createMockSource(
    Collection $items,
    Collection $users,
    string $minutesKey = 'test_reminder_minutes',
    ?string $emailKey = null,
    ?Notification $mailNotification = null,
): ReminderSource {
    return new class($items, $users, $minutesKey, $emailKey, $mailNotification) implements ReminderSource
    {
        public function __construct(
            private Collection $items,
            private Collection $users,
            private string $minutesKey,
            private ?string $emailKey,
            private ?Notification $mailNotification,
        ) {}

        public function getUpcomingRemindables(): Collection
        {
            return $this->items;
        }

        public function getReminderCategory(): NotificationCategory
        {
            return NotificationCategory::BulletinReminder;
        }

        public function buildNotificationData(Model $item, User $user, int $minutes): array
        {
            $label = $minutes === 1 ? '1 minute' : "{$minutes} minutes";

            return ['title' => "Test in {$label}", 'message' => 'Test message', 'url' => '/test'];
        }

        public function buildMailNotification(Model $item, User $user, int $minutes): ?Notification
        {
            return $this->mailNotification;
        }

        public function getGroupKey(Model $item, int $minutes): string
        {
            return "test_{$item->id}_{$minutes}m";
        }

        public function getUsersToNotify(Model $item): Collection
        {
            return $this->users;
        }

        public function getScheduledTime(Model $item): \Carbon\Carbon
        {
            return $item->scheduled_at ?? $item->start_time;
        }

        public function getUserReminderMinutes(User $user): array
        {
            $minutes = $user->notification_preferences[$this->minutesKey] ?? [15];

            return array_values(array_map('intval', $minutes));
        }

        public function getMinutesPreferenceKey(): string
        {
            return $this->minutesKey;
        }

        public function getEmailPreferenceKey(): ?string
        {
            return $this->emailKey;
        }
    };
}

test('sends notification when reminder time matches window', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $this->user->update([
        'notification_preferences' => ['test_reminder_minutes' => [10]],
    ]);

    $eventType = EventType::where('code', 'FD')->first();
    $event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(18),
    ]);
    $eventConfig = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'created_by_user_id' => $this->user->id,
    ]);
    $item = \App\Models\BulletinScheduleEntry::factory()->create([
        'event_id' => $event->id,
        'scheduled_at' => now()->addMinutes(10),
        'created_by' => $this->user->id,
    ]);

    $source = createMockSource(
        items: collect([$item]),
        users: collect([$this->user]),
    );

    $service = app(ReminderService::class);
    $count = $service->processSource($source);

    expect($count)->toBe(1);

    \Illuminate\Support\Facades\Notification::assertSentTo($this->user, InAppNotification::class);
});

test('skips notification when reminder time is outside window', function () {
    \Illuminate\Support\Facades\Notification::fake();

    $this->user->update([
        'notification_preferences' => ['test_reminder_minutes' => [30]],
    ]);

    $eventType = EventType::where('code', 'FD')->first();
    $event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(18),
    ]);
    $eventConfig = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'created_by_user_id' => $this->user->id,
    ]);
    $item = \App\Models\BulletinScheduleEntry::factory()->create([
        'event_id' => $event->id,
        'scheduled_at' => now()->addMinutes(10),
        'created_by' => $this->user->id,
    ]);

    $source = createMockSource(
        items: collect([$item]),
        users: collect([$this->user]),
    );

    $service = app(ReminderService::class);
    $count = $service->processSource($source);

    expect($count)->toBe(0);

    \Illuminate\Support\Facades\Notification::assertNothingSent();
});

test('does not duplicate already-sent reminders', function () {
    $this->user->update([
        'notification_preferences' => ['test_reminder_minutes' => [10]],
    ]);

    $eventType = EventType::where('code', 'FD')->first();
    $event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(18),
    ]);
    $eventConfig = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'created_by_user_id' => $this->user->id,
    ]);
    $item = \App\Models\BulletinScheduleEntry::factory()->create([
        'event_id' => $event->id,
        'scheduled_at' => now()->addMinutes(10),
        'created_by' => $this->user->id,
    ]);

    $source = createMockSource(
        items: collect([$item]),
        users: collect([$this->user]),
    );

    $service = app(ReminderService::class);

    // First run sends
    $count1 = $service->processSource($source);
    expect($count1)->toBe(1);

    // Second run deduplicates
    \Illuminate\Support\Facades\Notification::fake();
    $count2 = $service->processSource($source);
    expect($count2)->toBe(0);

    \Illuminate\Support\Facades\Notification::assertNothingSent();
});

test('returns zero for empty remindables', function () {
    $source = createMockSource(
        items: collect(),
        users: collect([$this->user]),
    );

    $service = app(ReminderService::class);
    $count = $service->processSource($source);

    expect($count)->toBe(0);
});
