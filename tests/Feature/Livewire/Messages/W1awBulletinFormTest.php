<?php

use App\Livewire\Messages\W1awBulletinForm;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use App\Models\W1awBulletin;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed([\Database\Seeders\EventTypeSeeder::class, \Database\Seeders\BonusTypeSeeder::class]);

    Permission::firstOrCreate(['name' => 'log-contacts']);

    $this->eventType = EventType::where('code', 'FD')->first();
    $this->event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);

    $this->operator = User::factory()->create();
    $this->operator->givePermissionTo('log-contacts');
});

describe('access control', function () {
    test('allows viewing page without log-contacts permission', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertSuccessful();
    });

    test('requires log-contacts permission to save', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('frequency', '7.0475')
            ->set('mode', 'cw')
            ->set('receivedAt', now()->format('Y-m-d\TH:i'))
            ->set('bulletinText', 'ARRL FIELD DAY MESSAGE TEST')
            ->call('save')
            ->assertForbidden();
    });
});

describe('creating bulletin', function () {
    test('can save a W1AW bulletin', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('frequency', '7.0475')
            ->set('mode', 'cw')
            ->set('receivedAt', now()->format('Y-m-d\TH:i'))
            ->set('bulletinText', 'ARRL FIELD DAY MESSAGE TEST')
            ->call('save')
            ->assertHasNoErrors();

        expect(W1awBulletin::where('event_configuration_id', $this->eventConfig->id)->count())->toBe(1);
    });

    test('validates required fields', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->call('save')
            ->assertHasErrors(['frequency', 'mode', 'receivedAt', 'bulletinText']);
    });
});

describe('editing bulletin', function () {
    test('loads existing bulletin for editing', function () {
        W1awBulletin::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'frequency' => '14.0475',
        ]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertSet('frequency', '14.0475');
    });
});

describe('reminder settings', function () {
    test('shows default 15-minute reminder for new user', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertSee('15 min');
    });

    test('can add a reminder minute', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('reminderMinute', 5)
            ->call('addReminderMinute')
            ->assertHasNoErrors()
            ->assertSee('5 min')
            ->assertSee('15 min');

        expect($this->operator->fresh()->getBulletinReminderMinutes())->toBe([5, 15]);
    });

    test('can remove a reminder minute', function () {
        $this->operator->setBulletinReminderMinutes([5, 15]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->call('removeReminderMinute', 15)
            ->assertSee('5 min')
            ->assertDontSee('15 min');

        expect($this->operator->fresh()->getBulletinReminderMinutes())->toBe([5]);
    });

    test('rejects duplicate reminder minute', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('reminderMinute', 15)
            ->call('addReminderMinute')
            ->assertHasErrors(['reminderMinute']);
    });

    test('rejects value below 1', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('reminderMinute', 0)
            ->call('addReminderMinute')
            ->assertHasErrors(['reminderMinute']);
    });

    test('rejects value above 60', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('reminderMinute', 61)
            ->call('addReminderMinute')
            ->assertHasErrors(['reminderMinute']);
    });

    test('rejects more than 5 reminders', function () {
        $this->operator->setBulletinReminderMinutes([1, 5, 10, 15, 30]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('reminderMinute', 45)
            ->call('addReminderMinute')
            ->assertHasErrors(['reminderMinute']);

        expect($this->operator->fresh()->getBulletinReminderMinutes())->toBe([1, 5, 10, 15, 30]);
    });

    test('shows hint when no reminders configured', function () {
        $this->operator->setBulletinReminderMinutes([]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertSee('No reminders configured', false);
    });

    test('hides add form when 5 reminders configured', function () {
        $this->operator->setBulletinReminderMinutes([1, 5, 10, 15, 30]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertDontSee('Minutes before');
    });
});
