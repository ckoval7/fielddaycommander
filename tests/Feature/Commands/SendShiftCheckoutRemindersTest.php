<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Notifications\ShiftCheckoutReminderMail;
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
    $this->shiftRole = ShiftRole::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Operator',
    ]);
});

test('sends checkout reminder when a checked-in user is past the 30-minute threshold', function () {
    Notification::fake();

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subMinutes(32),
    ]);

    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertSentTo($this->user, InAppNotification::class, function ($notification) {
        $data = $notification->toArray($this->user);

        return str_contains($data['title'], 'Forgot to check out')
            && str_contains($data['title'], 'Operator');
    });
});

test('does not send checkout reminder to checked-out users', function () {
    Notification::fake();

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subMinutes(32),
    ]);

    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => now()->subHours(3),
        'checked_out_at' => now()->subMinutes(10),
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertNotSentTo($this->user, InAppNotification::class);
    Notification::assertNotSentTo($this->user, ShiftCheckoutReminderMail::class);
});

test('does not send checkout reminder to no-show users', function () {
    Notification::fake();

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subMinutes(32),
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
            'shift_reminder_email' => true,
        ],
    ]);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subMinutes(32),
    ]);

    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertSentTo($this->user, ShiftCheckoutReminderMail::class);
});

test('does not send email when shift_reminder_email is disabled', function () {
    config(['mail.email_configured' => true]);
    Notification::fake();

    $this->user->update([
        'notification_preferences' => [
            'shift_reminder_email' => false,
        ],
    ]);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subMinutes(32),
    ]);

    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertNotSentTo($this->user, ShiftCheckoutReminderMail::class);
});

test('unsubscribed user gets no checkout reminder', function () {
    Notification::fake();

    $this->user->update([
        'notification_preferences' => [
            'categories' => ['shift_checkout_reminder' => false],
        ],
    ]);

    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subMinutes(32),
    ]);

    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    $this->artisan('reminders:send')->assertSuccessful();

    Notification::assertNotSentTo($this->user, InAppNotification::class);
});

test('does not resend already-delivered checkout reminder', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subMinutes(32),
    ]);

    ShiftAssignment::factory()->checkedIn()->create([
        'shift_id' => $shift->id,
        'user_id' => $this->user->id,
    ]);

    // First run sends
    $this->artisan('reminders:send')->assertSuccessful();
    expect($this->user->notifications()->count())->toBe(1);

    // Second run within the same firing window deduplicates via group_key
    Notification::fake();
    $this->artisan('reminders:send')->assertSuccessful();
    Notification::assertNothingSent();
});
