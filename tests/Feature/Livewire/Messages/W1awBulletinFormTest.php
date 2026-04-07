<?php

use App\Livewire\Messages\W1awBulletinForm;
use App\Models\AuditLog;
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

// Reminder settings tests have been relocated to tests/Feature/Livewire/Profile/UserProfileReminderSettingsTest.php
// since the reminder interval UI now lives in the profile notification preferences section.

describe('audit logging', function () {
    test('creating a bulletin logs to audit log', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('frequency', '7.0475')
            ->set('mode', 'cw')
            ->set('receivedAt', now()->format('Y-m-d\TH:i'))
            ->set('bulletinText', 'ARRL FIELD DAY MESSAGE')
            ->call('save')
            ->assertHasNoErrors();

        $bulletin = W1awBulletin::where('event_configuration_id', $this->eventConfig->id)->first();

        $auditLog = AuditLog::where('action', 'bulletin.created')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($this->operator->id);
        expect($auditLog->auditable_type)->toBe(W1awBulletin::class);
        expect($auditLog->auditable_id)->toBe($bulletin->id);
        expect($auditLog->new_values)->toMatchArray([
            'frequency' => '7.0475',
            'mode' => 'cw',
            'bulletin_text' => 'ARRL FIELD DAY MESSAGE',
        ]);
    });

    test('updating a bulletin logs old and new values', function () {
        $bulletin = W1awBulletin::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'frequency' => '7.0475',
            'mode' => 'cw',
            'received_at' => now(),
            'bulletin_text' => 'ORIGINAL TEXT',
        ]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->set('bulletinText', 'UPDATED TEXT')
            ->set('frequency', '14.0475')
            ->call('save')
            ->assertHasNoErrors();

        $auditLog = AuditLog::where('action', 'bulletin.updated')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->auditable_id)->toBe($bulletin->id);
        expect($auditLog->old_values)->toMatchArray([
            'frequency' => '7.0475',
            'bulletin_text' => 'ORIGINAL TEXT',
        ]);
        expect($auditLog->new_values)->toMatchArray([
            'frequency' => '14.0475',
            'bulletin_text' => 'UPDATED TEXT',
        ]);
    });

    test('deleting a bulletin logs to audit log', function () {
        $bulletin = W1awBulletin::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'frequency' => '7.0475',
            'mode' => 'cw',
            'received_at' => now(),
            'bulletin_text' => 'DELETE ME',
        ]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->call('deleteBulletin')
            ->assertHasNoErrors();

        $auditLog = AuditLog::where('action', 'bulletin.deleted')->first();
        expect($auditLog)->not->toBeNull();
        expect($auditLog->old_values)->toMatchArray([
            'frequency' => '7.0475',
            'mode' => 'cw',
            'bulletin_text' => 'DELETE ME',
        ]);
    });
});

describe('edit history', function () {
    test('shows edit history when bulletin has been updated', function () {
        $bulletin = W1awBulletin::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'frequency' => '7.0475',
            'mode' => 'cw',
            'received_at' => now(),
            'bulletin_text' => 'ORIGINAL TEXT',
        ]);

        AuditLog::create([
            'user_id' => $this->operator->id,
            'action' => 'bulletin.updated',
            'auditable_type' => W1awBulletin::class,
            'auditable_id' => $bulletin->id,
            'old_values' => ['bulletin_text' => 'FIRST DRAFT'],
            'new_values' => ['bulletin_text' => 'ORIGINAL TEXT'],
            'ip_address' => '127.0.0.1',
            'is_critical' => false,
        ]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertSee('Edit History')
            ->assertSee('FIRST DRAFT')
            ->assertSee('ORIGINAL TEXT');
    });

    test('does not show edit history when bulletin has no edits', function () {
        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertDontSee('Edit History');
    });

    test('shows creation entry in edit history', function () {
        $bulletin = W1awBulletin::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'user_id' => $this->operator->id,
            'frequency' => '7.0475',
            'mode' => 'cw',
            'received_at' => now(),
            'bulletin_text' => 'INITIAL TEXT',
        ]);

        AuditLog::create([
            'user_id' => $this->operator->id,
            'action' => 'bulletin.created',
            'auditable_type' => W1awBulletin::class,
            'auditable_id' => $bulletin->id,
            'new_values' => ['frequency' => '7.0475', 'mode' => 'cw', 'bulletin_text' => 'INITIAL TEXT'],
            'ip_address' => '127.0.0.1',
            'is_critical' => false,
        ]);

        Livewire::actingAs($this->operator)
            ->test(W1awBulletinForm::class, ['event' => $this->event])
            ->assertSee('Edit History')
            ->assertSee('Created bulletin');
    });
});
