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

    test('inReminderWindow scope finds entries within window', function () {
        // Within future window (10 min out) — should match
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(10),
        ]);
        // Within future window (55 min out) — should match
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(55),
        ]);
        // Too far in future (65 min out) — should not match
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->addMinutes(65),
        ]);
        // Recently past (3 min ago, within 5-min grace) — should match
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->subMinutes(3),
        ]);
        // Too far in past (10 min ago, outside grace) — should not match
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'scheduled_at' => now()->subMinutes(10),
        ]);

        $entries = BulletinScheduleEntry::inReminderWindow()->get();

        expect($entries)->toHaveCount(3);
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
