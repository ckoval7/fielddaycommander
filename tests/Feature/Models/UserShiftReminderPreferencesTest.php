<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('getShiftReminderMinutes returns default [15] when not configured', function () {
    expect($this->user->getShiftReminderMinutes())->toBe([15]);
});

test('getShiftReminderMinutes returns configured values', function () {
    $this->user->update([
        'notification_preferences' => ['shift_reminder_minutes' => [5, 30]],
    ]);

    expect($this->user->fresh()->getShiftReminderMinutes())->toBe([5, 30]);
});

test('setShiftReminderMinutes persists and deduplicates', function () {
    $this->user->setShiftReminderMinutes([10, 10, 5]);

    expect($this->user->fresh()->getShiftReminderMinutes())->toBe([5, 10]);
});

test('hasShiftReminderEmailEnabled returns false by default', function () {
    expect($this->user->hasShiftReminderEmailEnabled())->toBeFalse();
});

test('hasShiftReminderEmailEnabled returns true when enabled', function () {
    $this->user->update([
        'notification_preferences' => ['shift_reminder_email' => true],
    ]);

    expect($this->user->fresh()->hasShiftReminderEmailEnabled())->toBeTrue();
});
