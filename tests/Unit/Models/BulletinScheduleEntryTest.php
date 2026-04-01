<?php

use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\User;

uses()->group('unit', 'models');

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([\Database\Seeders\EventTypeSeeder::class]);
    $this->event = Event::factory()->create();
    $this->user = User::factory()->create();
});

describe('relationships', function () {
    test('belongs to an event', function () {
        $entry = BulletinScheduleEntry::factory()->create(['event_id' => $this->event->id]);

        expect($entry->event)->toBeInstanceOf(Event::class)
            ->and($entry->event->id)->toBe($this->event->id);
    });

    test('belongs to a creator', function () {
        $entry = BulletinScheduleEntry::factory()->create(['created_by' => $this->user->id]);

        expect($entry->creator)->toBeInstanceOf(User::class)
            ->and($entry->creator->id)->toBe($this->user->id);
    });
});

describe('scopes', function () {
    test('upcoming scope filters to future entries', function () {
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addHours(2),
        ]);
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->subHours(2),
        ]);

        $upcoming = BulletinScheduleEntry::upcoming()->get();

        expect($upcoming)->toHaveCount(1);
    });

    test('forEvent scope filters by event id', function () {
        $otherEvent = Event::factory()->create();

        BulletinScheduleEntry::factory()->create(['event_id' => $this->event->id]);
        BulletinScheduleEntry::factory()->create(['event_id' => $otherEvent->id]);

        $entries = BulletinScheduleEntry::forEvent($this->event->id)->get();

        expect($entries)->toHaveCount(1);
    });

    test('pendingNotification scope finds entries within 15 minute window', function () {
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(10),
            'notification_sent' => false,
        ]);
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(20),
            'notification_sent' => false,
        ]);
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(5),
            'notification_sent' => true,
        ]);

        $pending = BulletinScheduleEntry::pendingNotification()->get();

        expect($pending)->toHaveCount(1);
    });
});

describe('accessors', function () {
    test('mode label returns formatted mode name', function () {
        $cw = BulletinScheduleEntry::factory()->make(['mode' => 'cw']);
        $digital = BulletinScheduleEntry::factory()->make(['mode' => 'digital']);
        $phone = BulletinScheduleEntry::factory()->make(['mode' => 'phone']);

        expect($cw->mode_label)->toBe('CW')
            ->and($digital->mode_label)->toBe('Digital')
            ->and($phone->mode_label)->toBe('Phone');
    });
});
