<?php

use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use App\Notifications\InAppNotification;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $this->eventType = EventType::where('code', 'FD')->first();
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(18),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'created_by_user_id' => $this->user->id,
    ]);
});

describe('per-user configurable reminders', function () {
    test('sends reminder at user-configured interval', function () {
        Notification::fake();

        $this->user->setBulletinReminderMinutes([10]);

        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(10),
            'mode' => 'cw',
            'frequencies' => '7.0475, 14.0475',
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')
            ->expectsOutputToContain('Sent 1 reminder(s) via BulletinReminderSource')
            ->assertSuccessful();

        Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) use ($entry) {
            $data = $notification->toArray($this->user);

            return $data['title'] === 'W1AW Bulletin in 10 minutes'
                && $data['group_key'] === "bulletin_reminder_{$entry->id}_10m";
        });
    });

    test('user with default preferences gets 15-minute reminder', function () {
        Notification::fake();

        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(15),
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) {
            $data = $notification->toArray($this->user);

            return $data['title'] === 'W1AW Bulletin in 15 minutes';
        });
    });

    test('user with multiple intervals gets separate notifications at correct times', function () {
        Notification::fake();

        $this->user->setBulletinReminderMinutes([15, 2]);

        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(15),
            'mode' => 'cw',
            'frequencies' => '7.0475',
            'created_by' => $this->user->id,
        ]);

        // At T-15, only the 15-min reminder should fire
        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertSentToTimes($this->user, InAppNotification::class, 1);
        Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) use ($entry) {
            $data = $notification->toArray($this->user);

            return $data['group_key'] === "bulletin_reminder_{$entry->id}_15m";
        });
    });

    test('two-minute reminder fires at correct time', function () {
        Notification::fake();

        $this->user->setBulletinReminderMinutes([15, 2]);

        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(2),
            'mode' => 'cw',
            'frequencies' => '7.0475',
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) use ($entry) {
            $data = $notification->toArray($this->user);

            return $data['group_key'] === "bulletin_reminder_{$entry->id}_2m";
        });
    });

    test('user with empty array gets no reminders', function () {
        Notification::fake();

        $this->user->setBulletinReminderMinutes([]);

        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(10),
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertNothingSent();
    });

    test('different users with different preferences get their own reminders', function () {
        Notification::fake();

        $userA = $this->user;
        $userA->setBulletinReminderMinutes([5]);

        $userB = User::factory()->create();
        $userB->setBulletinReminderMinutes([10]);

        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(5),
            'created_by' => $userA->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        // User A has 5-min reminder, entry is 5 min away — should fire
        Notification::assertSentTo($userA, InAppNotification::class);

        // User B has 10-min reminder, entry is 5 min away — should NOT fire
        Notification::assertNotSentTo($userB, InAppNotification::class);
    });

    test('unsubscribed users get no reminders', function () {
        Notification::fake();

        $this->user->update([
            'notification_preferences' => [
                'categories' => ['bulletin_reminder' => false],
                'bulletin_reminder_minutes' => [15],
            ],
        ]);

        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(15),
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertNothingSent();
    });
});

describe('deduplication', function () {
    test('does not resend reminder that was already delivered', function () {
        $this->user->setBulletinReminderMinutes([10]);

        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(10),
            'mode' => 'cw',
            'frequencies' => '7.0475',
            'created_by' => $this->user->id,
        ]);

        // First run — sends real notification (not faked)
        $this->artisan('reminders:send')->assertSuccessful();

        expect($this->user->notifications()->where('data->group_key', "bulletin_reminder_{$entry->id}_10m")->count())->toBe(1);

        // Second run — should not duplicate
        Notification::fake();
        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertNothingSent();
    });
});

describe('edge cases', function () {
    test('does not send for entries belonging to inactive events', function () {
        Notification::fake();

        $inactiveEvent = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'start_time' => now()->addDays(30),
            'end_time' => now()->addDays(31),
        ]);

        BulletinScheduleEntry::factory()->create([
            'event_id' => $inactiveEvent->id,
            'scheduled_at' => now()->addMinutes(10),
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertNothingSent();
    });

    test('notification url is a relative path', function () {
        Notification::fake();

        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(15),
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) {
            $data = $notification->toArray($this->user);

            return $data['url'] === '/w1aw-bulletin';
        });
    });

    test('notification message includes mode and frequencies', function () {
        Notification::fake();

        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(15),
            'mode' => 'cw',
            'frequencies' => '7.0475, 14.0475',
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) {
            $data = $notification->toArray($this->user);

            return str_contains($data['message'], 'CW')
                && str_contains($data['message'], '7.0475, 14.0475');
        });
    });

    test('singular minute label for 1-minute reminder', function () {
        Notification::fake();

        $this->user->setBulletinReminderMinutes([1]);

        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(1),
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) {
            $data = $notification->toArray($this->user);

            return $data['title'] === 'W1AW Bulletin in 1 minute';
        });
    });

    test('entry outside 60-minute window is ignored', function () {
        Notification::fake();

        $this->user->setBulletinReminderMinutes([60]);

        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(70),
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertNothingSent();
    });

    test('sends reminders during event setup window before event starts', function () {
        Notification::fake();

        // Delete the beforeEach active event to test setup window in isolation
        $this->event->delete();

        // Create an event in setup window (setup started, but event hasn't started yet)
        $setupEvent = Event::factory()->create([
            'event_type_id' => $this->eventType->id,
            'setup_allowed_from' => now()->subHours(6),
            'start_time' => now()->addHours(6),
            'end_time' => now()->addHours(30),
        ]);

        BulletinScheduleEntry::factory()->create([
            'event_id' => $setupEvent->id,
            'scheduled_at' => now()->addMinutes(15),
            'mode' => 'cw',
            'frequencies' => '7.0475',
            'created_by' => $this->user->id,
        ]);

        $this->artisan('reminders:send')->assertSuccessful();

        Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) {
            $data = $notification->toArray($this->user);

            return $data['title'] === 'W1AW Bulletin in 15 minutes';
        });
    });
});
