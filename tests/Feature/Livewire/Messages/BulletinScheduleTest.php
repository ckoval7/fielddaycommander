<?php

use App\Livewire\Messages\W1awBulletinForm;
use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([\Database\Seeders\EventTypeSeeder::class, \Database\Seeders\BonusTypeSeeder::class]);

    Permission::firstOrCreate(['name' => 'log-contacts']);
    Permission::firstOrCreate(['name' => 'manage-event-config']);

    $this->eventType = EventType::where('code', 'FD')->first();
    $this->event = Event::factory()->create(['event_type_id' => $this->eventType->id]);
    $this->eventConfig = EventConfiguration::factory()->create(['event_id' => $this->event->id]);

    $this->operator = User::factory()->create();
    $this->operator->givePermissionTo('log-contacts');

    $this->manager = User::factory()->create();
    $this->manager->givePermissionTo(['log-contacts', 'manage-event-config']);
});

describe('schedule display', function () {
    // These three tests verify blade template rendering and will pass after Task 5 adds the schedule UI.
    test('all users see the bulletin schedule', function () {
        BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'mode' => 'cw',
            'frequencies' => '7.0475, 14.0475',
            'scheduled_at' => now()->addHours(2),
        ]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertSee('7.0475, 14.0475')
            ->assertSee('CW');
    })->skip('Blade template schedule UI added in Task 5');

    test('operator cannot see schedule management controls', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertDontSee('Add Transmission');
    })->skip('Blade template schedule UI added in Task 5');

    test('manager can see schedule management controls', function () {
        Livewire::actingAs($this->manager)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertSee('Add Transmission');
    })->skip('Blade template schedule UI added in Task 5');
});

describe('schedule management', function () {
    test('manager can add a schedule entry', function () {
        Livewire::actingAs($this->manager)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('scheduleMode', 'cw')
            ->set('scheduleFrequencies', '7.0475, 14.0475')
            ->set('scheduleSource', 'W1AW')
            ->set('scheduleScheduledAt', now()->addHours(2)->format('Y-m-d\TH:i'))
            ->call('addScheduleEntry')
            ->assertHasNoErrors();

        expect(BulletinScheduleEntry::where('event_id', $this->event->id)->count())->toBe(1);
    });

    test('operator cannot add a schedule entry', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('scheduleMode', 'cw')
            ->set('scheduleFrequencies', '7.0475')
            ->set('scheduleSource', 'W1AW')
            ->set('scheduleScheduledAt', now()->addHours(2)->format('Y-m-d\TH:i'))
            ->call('addScheduleEntry')
            ->assertForbidden();
    });

    test('manager can delete a schedule entry', function () {
        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'created_by' => $this->manager->id,
        ]);

        Livewire::actingAs($this->manager)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->call('deleteScheduleEntry', $entry->id)
            ->assertHasNoErrors();

        expect(BulletinScheduleEntry::find($entry->id))->toBeNull();
    });

    test('operator cannot delete a schedule entry', function () {
        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
        ]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->call('deleteScheduleEntry', $entry->id)
            ->assertForbidden();
    });

    test('validates required fields when adding entry', function () {
        Livewire::actingAs($this->manager)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->call('addScheduleEntry')
            ->assertHasErrors(['scheduleMode', 'scheduleFrequencies', 'scheduleScheduledAt']);
    });

    test('manager can edit a schedule entry', function () {
        $entry = BulletinScheduleEntry::factory()->create([
            'event_id' => $this->event->id,
            'mode' => 'cw',
            'frequencies' => '7.0475',
        ]);

        Livewire::actingAs($this->manager)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->call('editScheduleEntry', $entry->id)
            ->assertSet('scheduleMode', 'cw')
            ->assertSet('scheduleFrequencies', '7.0475')
            ->set('scheduleFrequencies', '7.0475, 14.0475')
            ->call('updateScheduleEntry')
            ->assertHasNoErrors();

        expect($entry->fresh()->frequencies)->toBe('7.0475, 14.0475');
    });
});
