<?php

use App\Enums\NotificationCategory;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use App\Services\Reminders\ShiftReminderSource;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([\Database\Seeders\EventTypeSeeder::class, \Database\Seeders\BonusTypeSeeder::class]);

    $this->user = User::factory()->create();
    $this->eventType = EventType::where('code', 'FD')->first();
    $this->event = Event::factory()->create([
        'event_type_id' => $this->eventType->id,
        'start_time' => now()->subHours(6),
        'end_time' => now()->addHours(18),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'created_by_user_id' => $this->user->id,
    ]);
    $this->shiftRole = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Operator',
    ]);

    $this->source = new ShiftReminderSource;
});

test('getUpcomingRemindables returns shifts in reminder window', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(30),
        'end_time' => now()->addMinutes(150),
    ]);

    $items = $this->source->getUpcomingRemindables();

    expect($items)->toHaveCount(1)
        ->and($items->first()->id)->toBe($shift->id);
});

test('getUpcomingRemindables excludes shifts beyond 60-minute window', function () {
    Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(90),
        'end_time' => now()->addMinutes(210),
    ]);

    $items = $this->source->getUpcomingRemindables();

    expect($items)->toHaveCount(0);
});

test('getUpcomingRemindables excludes past shifts', function () {
    Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->subMinutes(120),
        'end_time' => now()->subMinutes(1),
    ]);

    $items = $this->source->getUpcomingRemindables();

    expect($items)->toHaveCount(0);
});

test('getReminderCategory returns ShiftCheckinReminder', function () {
    expect($this->source->getReminderCategory())->toBe(NotificationCategory::ShiftCheckinReminder);
});

test('getUsersToNotify returns only scheduled assignment users', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);

    $scheduledUser = User::factory()->create();
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $scheduledUser->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $checkedInUser = User::factory()->create();
    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $checkedInUser->id,
    ]);

    $noShowUser = User::factory()->create();
    ShiftAssignment::factory()->noShow()->create([
        'shift_id' => $shift->id,
        'user_id' => $noShowUser->id,
    ]);

    $users = $this->source->getUsersToNotify($shift);

    expect($users)->toHaveCount(1)
        ->and($users->first()->id)->toBe($scheduledUser->id);
});

test('buildNotificationData returns correct data', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);

    $data = $this->source->buildNotificationData($shift, $this->user, 15);

    expect($data['title'])->toBe('Shift in 15 minutes: Operator')
        ->and($data['message'])->toContain($shift->start_time->format('H:i'))
        ->and($data['url'])->toBe('/schedule/my-shifts');
});

test('buildNotificationData uses singular minute label', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(1),
        'end_time' => now()->addMinutes(121),
    ]);

    $data = $this->source->buildNotificationData($shift, $this->user, 1);

    expect($data['title'])->toBe('Shift in 1 minute: Operator');
});

test('getGroupKey includes shift id and minutes', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);

    $key = $this->source->getGroupKey($shift, 15);

    expect($key)->toBe("shift_reminder_{$shift->id}_15m");
});

test('getMinutesPreferenceKey returns shift_reminder_minutes', function () {
    expect($this->source->getMinutesPreferenceKey())->toBe('shift_reminder_minutes');
});

test('getEmailPreferenceKey returns shift_reminder_email', function () {
    expect($this->source->getEmailPreferenceKey())->toBe('shift_reminder_email');
});
