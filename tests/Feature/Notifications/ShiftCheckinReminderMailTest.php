<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Shift;
use App\Models\ShiftRole;
use App\Models\User;
use App\Notifications\ShiftCheckinReminderMail;
use Illuminate\Notifications\Messages\MailMessage;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([\Database\Seeders\EventTypeSeeder::class, \Database\Seeders\BonusTypeSeeder::class]);

    $this->user = User::factory()->create(['first_name' => 'John']);
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
        'name' => 'Station Operator',
    ]);
    $this->shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->addMinutes(15),
        'end_time' => now()->addMinutes(135),
    ]);
});

test('notification sends via mail channel', function () {
    $notification = new ShiftCheckinReminderMail($this->shift, 15);

    expect($notification->via($this->user))->toBe(['mail']);
});

test('mail has correct subject', function () {
    $notification = new ShiftCheckinReminderMail($this->shift, 15);
    $mail = $notification->toMail($this->user);

    expect($mail)->toBeInstanceOf(MailMessage::class);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain('Station Operator')
        ->and($rendered)->toContain('15 minutes');
});

test('mail contains shift time range', function () {
    $notification = new ShiftCheckinReminderMail($this->shift, 15);
    $mail = $notification->toMail($this->user);
    $rendered = $mail->render()->toHtml();

    expect($rendered)->toContain($this->shift->start_time->format('H:i'))
        ->and($rendered)->toContain($this->shift->end_time->format('H:i'));
});

test('mail contains view my shifts action button', function () {
    $notification = new ShiftCheckinReminderMail($this->shift, 15);
    $mail = $notification->toMail($this->user);
    $rendered = $mail->render()->toHtml();

    expect($rendered)->toContain('View My Shifts');
});

test('mail uses singular minute for 1 minute reminder', function () {
    $notification = new ShiftCheckinReminderMail($this->shift, 1);
    $mail = $notification->toMail($this->user);

    expect($mail->subject)->toContain('1 minute');
});

test('mail greets user by first name', function () {
    $notification = new ShiftCheckinReminderMail($this->shift, 15);
    $mail = $notification->toMail($this->user);
    $rendered = $mail->render()->toHtml();

    expect($rendered)->toContain('John');
});
