<?php

use App\Livewire\Profile\UserProfile;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('bulletin reminder settings', function () {
    test('can add a bulletin reminder minute', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('bulletinReminderMinute', 5)
            ->call('addBulletinReminderMinute')
            ->assertHasNoErrors();

        expect($this->user->fresh()->getBulletinReminderMinutes())->toBe([5, 15]);
    });

    test('can remove a bulletin reminder minute', function () {
        $this->user->setBulletinReminderMinutes([5, 15]);

        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->call('removeBulletinReminderMinute', 15);

        expect($this->user->fresh()->getBulletinReminderMinutes())->toBe([5]);
    });

    test('rejects duplicate bulletin reminder minute', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('bulletinReminderMinute', 15)
            ->call('addBulletinReminderMinute')
            ->assertHasErrors(['bulletinReminderMinute']);
    });

    test('rejects bulletin reminder value below 1', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('bulletinReminderMinute', 0)
            ->call('addBulletinReminderMinute')
            ->assertHasErrors(['bulletinReminderMinute']);
    });

    test('rejects bulletin reminder value above 60', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('bulletinReminderMinute', 61)
            ->call('addBulletinReminderMinute')
            ->assertHasErrors(['bulletinReminderMinute']);
    });

    test('rejects more than 5 bulletin reminders', function () {
        $this->user->setBulletinReminderMinutes([1, 5, 10, 15, 30]);

        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('bulletinReminderMinute', 45)
            ->call('addBulletinReminderMinute')
            ->assertHasErrors(['bulletinReminderMinute']);

        expect($this->user->fresh()->getBulletinReminderMinutes())->toBe([1, 5, 10, 15, 30]);
    });
});

describe('shift reminder settings', function () {
    test('can add a shift reminder minute', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('shiftReminderMinute', 5)
            ->call('addShiftReminderMinute')
            ->assertHasNoErrors();

        expect($this->user->fresh()->getShiftReminderMinutes())->toBe([5, 15]);
    });

    test('can remove a shift reminder minute', function () {
        $this->user->setShiftReminderMinutes([5, 15]);

        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->call('removeShiftReminderMinute', 15);

        expect($this->user->fresh()->getShiftReminderMinutes())->toBe([5]);
    });

    test('rejects duplicate shift reminder minute', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('shiftReminderMinute', 15)
            ->call('addShiftReminderMinute')
            ->assertHasErrors(['shiftReminderMinute']);
    });

    test('rejects shift reminder value below 1', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('shiftReminderMinute', 0)
            ->call('addShiftReminderMinute')
            ->assertHasErrors(['shiftReminderMinute']);
    });

    test('rejects shift reminder value above 60', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('shiftReminderMinute', 61)
            ->call('addShiftReminderMinute')
            ->assertHasErrors(['shiftReminderMinute']);
    });

    test('rejects more than 5 shift reminders', function () {
        $this->user->setShiftReminderMinutes([1, 5, 10, 15, 30]);

        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('shiftReminderMinute', 45)
            ->call('addShiftReminderMinute')
            ->assertHasErrors(['shiftReminderMinute']);

        expect($this->user->fresh()->getShiftReminderMinutes())->toBe([1, 5, 10, 15, 30]);
    });
});

describe('reminder preservation on profile save', function () {
    test('saveProfile preserves bulletin reminder minutes', function () {
        $this->user->setBulletinReminderMinutes([5, 10, 30]);

        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('first_name', 'Updated')
            ->call('saveProfile')
            ->assertHasNoErrors();

        expect($this->user->fresh()->getBulletinReminderMinutes())->toBe([5, 10, 30]);
    });

    test('saveProfile preserves shift reminder minutes', function () {
        $this->user->setShiftReminderMinutes([5, 10, 30]);

        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->set('first_name', 'Updated')
            ->call('saveProfile')
            ->assertHasNoErrors();

        expect($this->user->fresh()->getShiftReminderMinutes())->toBe([5, 10, 30]);
    });
});

describe('shift category toggle and email', function () {
    test('shift check-in reminder category defaults to enabled', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->assertSet('notify_shift_checkin_reminder', true);
    });

    test('shift reminder email defaults to disabled', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->assertSet('shift_reminder_email', false);
    });

    test('toggleAllCategories includes shift check-in reminder', function () {
        Livewire::actingAs($this->user)
            ->test(UserProfile::class)
            ->call('toggleAllCategories', false)
            ->assertSet('notify_shift_checkin_reminder', false)
            ->call('toggleAllCategories', true)
            ->assertSet('notify_shift_checkin_reminder', true);
    });
});
