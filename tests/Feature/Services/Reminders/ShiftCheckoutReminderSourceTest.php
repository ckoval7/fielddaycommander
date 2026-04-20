<?php

use App\Enums\NotificationCategory;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use App\Services\Reminders\ShiftCheckoutReminderSource;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

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

    $this->source = new ShiftCheckoutReminderSource;
});

function makeShift(array $overrides = []): Shift
{
    return Shift::factory()->create(array_merge([
        'event_configuration_id' => test()->eventConfig->id,
        'shift_role_id' => test()->shiftRole->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subMinutes(32),
    ], $overrides));
}

test('returns checked-in assignments whose shift ended 30-35 minutes ago', function () {
    $shift = makeShift(['end_time' => now()->subMinutes(32)]);

    $assignment = ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $items = $this->source->getUpcomingRemindables();

    expect($items)->toHaveCount(1)
        ->and($items->first()->id)->toBe($assignment->id);
});

test('excludes assignments whose shift ended less than 30 minutes ago', function () {
    $shift = makeShift(['end_time' => now()->subMinutes(29)]);

    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->source->getUpcomingRemindables())->toHaveCount(0);
});

test('excludes assignments whose shift ended more than 35 minutes ago', function () {
    $shift = makeShift(['end_time' => now()->subMinutes(40)]);

    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->source->getUpcomingRemindables())->toHaveCount(0);
});

test('excludes checked-out assignments', function () {
    $shift = makeShift();

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => now()->subHours(3),
        'checked_out_at' => now()->subMinutes(32),
    ]);

    expect($this->source->getUpcomingRemindables())->toHaveCount(0);
});

test('excludes no-show assignments', function () {
    $shift = makeShift();

    ShiftAssignment::factory()->noShow()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->source->getUpcomingRemindables())->toHaveCount(0);
});

test('excludes scheduled-only assignments (never checked in)', function () {
    $shift = makeShift();

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    expect($this->source->getUpcomingRemindables())->toHaveCount(0);
});

test('excludes future shifts', function () {
    $shift = makeShift(['start_time' => now()->addHours(1), 'end_time' => now()->addHours(3)]);

    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->source->getUpcomingRemindables())->toHaveCount(0);
});

test('getReminderCategory returns ShiftCheckoutReminder', function () {
    expect($this->source->getReminderCategory())->toBe(NotificationCategory::ShiftCheckoutReminder);
});

test('getUsersToNotify returns the assignment user', function () {
    $shift = makeShift();
    $assignment = ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $users = $this->source->getUsersToNotify($assignment);

    expect($users)->toHaveCount(1)
        ->and($users->first()->id)->toBe($this->user->id);
});

test('buildNotificationData returns correct data', function () {
    $shift = makeShift();
    $assignment = ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $data = $this->source->buildNotificationData($assignment, $this->user, 0);

    expect($data['title'])->toBe('Forgot to check out? Operator')
        ->and($data['message'])->toContain('Operator')
        ->and($data['message'])->toContain($shift->end_time->format('H:i'))
        ->and($data['url'])->toBe('/schedule/my-shifts');
});

test('getGroupKey is keyed by assignment id', function () {
    $shift = makeShift();
    $assignment = ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->source->getGroupKey($assignment, 0))->toBe("shift_checkout_reminder_{$assignment->id}");
});

test('getScheduledTime returns end_time plus 30 minutes', function () {
    $shift = makeShift(['end_time' => now()->subMinutes(32)]);
    $assignment = ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->source->getScheduledTime($assignment)->eq($shift->end_time->copy()->addMinutes(30)))->toBeTrue();
});

test('getUserReminderMinutes returns [0]', function () {
    expect($this->source->getUserReminderMinutes($this->user))->toBe([0]);
});

test('getEmailPreferenceKey returns shift_reminder_email', function () {
    expect($this->source->getEmailPreferenceKey())->toBe('shift_reminder_email');
});
