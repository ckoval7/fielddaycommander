<?php

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Shift;
use App\Models\ShiftRole;
use App\Models\User;
use App\Notifications\ShiftCheckoutReminderMail;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    $this->user = User::factory()->create(['first_name' => 'Alex']);
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
});

test('renders subject, role name, and end time', function () {
    $shift = Shift::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'shift_role_id' => $this->shiftRole->id,
        'start_time' => now()->subHours(3),
        'end_time' => now()->subMinutes(30),
    ]);

    $mail = (new ShiftCheckoutReminderMail($shift))->toMail($this->user);

    expect($mail->subject)->toBe('Check-out reminder: Operator');
    expect($mail->greeting)->toBe('Hello Alex,');

    $endTime = $shift->end_time->format('H:i').' UTC';
    $introLines = collect($mail->introLines)->implode(' ');
    expect($introLines)->toContain('Operator');
    expect($introLines)->toContain($endTime);
    expect($mail->actionUrl)->toBe(route('schedule.my-shifts'));
});
