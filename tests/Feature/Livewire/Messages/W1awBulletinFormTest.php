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
    test('requires log-contacts permission', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
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
