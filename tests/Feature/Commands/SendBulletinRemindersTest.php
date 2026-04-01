<?php

use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([\Database\Seeders\EventTypeSeeder::class, \Database\Seeders\BonusTypeSeeder::class]);

    $this->eventType = EventType::where('code', 'FD')->first();
    $this->event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(18),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->user = User::factory()->create();
});

describe('sending bulletin reminders', function () {
    test('sends notifications for entries within 15 minute window', function () {
        Notification::fake();

        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(10),
            'mode' => 'cw',
            'frequencies' => '7.0475, 14.0475',
            'source' => 'W1AW',
            'notification_sent' => false,
        ]);

        $this->artisan('bulletins:send-reminders')
            ->expectsOutputToContain('Sent reminder')
            ->assertSuccessful();

        expect($entry->fresh()->notification_sent)->toBeTrue();

        Notification::assertSentTo(
            $this->user,
            \App\Notifications\InAppNotification::class,
        );
    });

    test('does not send for entries outside 15 minute window', function () {
        Notification::fake();

        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(20),
            'notification_sent' => false,
        ]);

        $this->artisan('bulletins:send-reminders')
            ->assertSuccessful();

        expect($entry->fresh()->notification_sent)->toBeFalse();

        Notification::assertNothingSent();
    });

    test('does not resend for entries already notified', function () {
        Notification::fake();

        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(10),
            'notification_sent' => true,
        ]);

        $this->artisan('bulletins:send-reminders')
            ->assertSuccessful();

        Notification::assertNothingSent();
    });

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
            'notification_sent' => false,
        ]);

        $this->artisan('bulletins:send-reminders')
            ->assertSuccessful();

        Notification::assertNothingSent();
    });

    test('notification message includes mode, frequencies, and time', function () {
        Notification::fake();

        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(10),
            'mode' => 'cw',
            'frequencies' => '7.0475, 14.0475',
            'source' => 'W1AW',
            'notification_sent' => false,
        ]);

        $this->artisan('bulletins:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->user, \App\Notifications\InAppNotification::class, function ($notification) {
            $data = $notification->toArray($this->user);

            return str_contains($data['message'], 'CW')
                && str_contains($data['message'], '7.0475, 14.0475');
        });
    });
});
