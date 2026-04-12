<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Notifications\ShiftCheckinReminderMail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([\Database\Seeders\EventTypeSeeder::class, \Database\Seeders\BonusTypeSeeder::class]);

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
    $this->shiftRole = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Operator',
    ]);
});

test('sends shift reminder at configured interval', function () {
    Notification::fake();

    $this->user->setShiftReminderMinutes([10]);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(10),
        'end_time' => now()->addMinutes(130),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) {
        $data = $notification->toArray($this->user);

        return str_contains($data['title'], 'Shift in 10 minutes')
            && str_contains($data['title'], 'Operator');
    });
});

test('does not send reminder to checked-in users', function () {
    Notification::fake();

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);

    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertNotSentTo($this->user, InAppNotification::class);
});

test('does not send reminder to no-show users', function () {
    Notification::fake();

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);

    ShiftAssignment::factory()->noShow()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertNotSentTo($this->user, InAppNotification::class);
});

test('sends email when shift_reminder_email is enabled', function () {
    config(['mail.email_configured' => true]);
    Notification::fake();

    $this->user->update([
        'notification_preferences' => [
            'shift_reminder_minutes' => [15],
            'shift_reminder_email' => true,
        ],
    ]);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertSentTo($this->user, ShiftCheckinReminderMail::class);
});

test('does not send email when shift_reminder_email is disabled', function () {
    Notification::fake();

    $this->user->setShiftReminderMinutes([15]);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertNotSentTo($this->user, ShiftCheckinReminderMail::class);
});

test('unsubscribed user gets no shift reminders', function () {
    Notification::fake();

    $this->user->update([
        'notification_preferences' => [
            'categories' => ['shift_checkin_reminder' => false],
            'shift_reminder_minutes' => [15],
        ],
    ]);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertNotSentTo($this->user, InAppNotification::class);
});

test('does not resend already-delivered shift reminder', function () {
    $this->user->setShiftReminderMinutes([15]);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_SCHEDULED,
    ]);

    // First run sends
    $this->artisan('reminders:send')->assertSuccessful();
    expect($this->user->notifications()->count())->toBe(1);

    // Second run deduplicates
    Notification::fake();
    $this->artisan('reminders:send')->assertSuccessful();
    Notification::assertNothingSent();
});
