<?php

use App\Livewire\Logbook\ContactEditor;
use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Permission::findOrCreate('edit-contacts', 'web');

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('edit-contacts');
    $this->actingAs($this->user);

    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    $this->band = Band::first() ?? Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175]);
    $this->otherBand = Band::where('name', '!=', '20m')->first() ?? Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0]);
    $this->mode = Mode::first() ?? Mode::create(['name' => 'SSB', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::where('is_active', true)->first() ?? Section::create(['name' => 'Connecticut', 'code' => 'CT', 'is_active' => true]);

    $this->station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
    $this->session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'qso_count' => 5,
    ]);

    $this->contact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'callsign' => 'W1AW',
        'exchange_class' => '3A',
        'notes' => null,
    ]);
});

describe('openEdit', function () {
    test('loads contact and opens modal', function () {
        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->assertSet('showModal', true)
            ->assertSet('callsign', 'W1AW')
            ->assertSet('exchange_class', '3A')
            ->assertSet('section_id', $this->section->id)
            ->assertSet('band_id', $this->band->id)
            ->assertSet('mode_id', $this->mode->id)
            ->assertSet('notes', '');
    });

    test('loads exchange_class from contact', function () {
        $this->contact->update(['exchange_class' => '2A']);

        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->assertSet('exchange_class', '2A');
    });

    test('denies access without edit-contacts permission', function () {
        $regularUser = User::factory()->create();
        $this->actingAs($regularUser);

        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->assertForbidden();
    });
});

describe('save', function () {
    test('updates contact fields and closes modal', function () {
        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->set('callsign', 'K1ABC')
            ->set('exchange_class', '2A')
            ->set('band_id', $this->otherBand->id)
            ->set('notes', 'Corrected callsign')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false)
            ->assertDispatched('contact-updated');

        $this->contact->refresh();
        expect($this->contact->callsign)->toBe('K1ABC')
            ->and($this->contact->exchange_class)->toBe('2A')
            ->and($this->contact->band_id)->toBe($this->otherBand->id)
            ->and($this->contact->notes)->toBe('Corrected callsign');
    });

    test('creates audit log entry on update', function () {
        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->set('callsign', 'K1ABC')
            ->set('exchange_class', '2A')
            ->call('save');

        $auditLog = AuditLog::where('action', 'contact.updated')
            ->where('auditable_id', $this->contact->id)
            ->first();

        expect($auditLog)->not->toBeNull()
            ->and($auditLog->old_values)->toHaveKey('callsign', 'W1AW')
            ->and($auditLog->new_values)->toHaveKey('callsign', 'K1ABC');
    });

    test('validates required fields', function () {
        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->set('callsign', '')
            ->set('exchange_class', '')
            ->set('section_id', null)
            ->call('save')
            ->assertHasErrors(['callsign', 'exchange_class', 'section_id']);
    });

    test('validates exchange_class format', function () {
        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->set('exchange_class', 'INVALID')
            ->call('save')
            ->assertHasErrors(['exchange_class']);
    });

    test('validates section_id exists', function () {
        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->set('section_id', 99999)
            ->call('save')
            ->assertHasErrors(['section_id']);
    });

    test('validates band_id exists', function () {
        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->set('band_id', 99999)
            ->call('save')
            ->assertHasErrors(['band_id']);
    });

    test('validates mode_id exists', function () {
        Livewire::test(ContactEditor::class)
            ->call('openEdit', $this->contact->id)
            ->set('mode_id', 99999)
            ->call('save')
            ->assertHasErrors(['mode_id']);
    });
});

describe('deleteContact', function () {
    test('soft-deletes contact and decrements session qso_count', function () {
        Livewire::test(ContactEditor::class)
            ->call('deleteContact', $this->contact->id)
            ->assertDispatched('contact-deleted');

        expect($this->contact->fresh()->trashed())->toBeTrue();

        $this->session->refresh();
        expect($this->session->qso_count)->toBe(4);
    });

    test('creates audit log entry on delete', function () {
        Livewire::test(ContactEditor::class)
            ->call('deleteContact', $this->contact->id);

        $auditLog = AuditLog::where('action', 'contact.deleted')
            ->where('auditable_id', $this->contact->id)
            ->first();

        expect($auditLog)->not->toBeNull()
            ->and($auditLog->old_values)->toHaveKey('callsign', 'W1AW');
    });

    test('denies access without edit-contacts permission', function () {
        $regularUser = User::factory()->create();
        $this->actingAs($regularUser);

        Livewire::test(ContactEditor::class)
            ->call('deleteContact', $this->contact->id)
            ->assertForbidden();
    });
});

describe('restoreContact', function () {
    beforeEach(function () {
        $this->contact->delete();
        $this->session->decrement('qso_count');
    });

    test('restores soft-deleted contact and increments session qso_count', function () {
        Livewire::test(ContactEditor::class)
            ->call('restoreContact', $this->contact->id)
            ->assertDispatched('contact-restored');

        expect($this->contact->fresh()->trashed())->toBeFalse();

        $this->session->refresh();
        expect($this->session->qso_count)->toBe(5);
    });

    test('creates audit log entry on restore', function () {
        Livewire::test(ContactEditor::class)
            ->call('restoreContact', $this->contact->id);

        $auditLog = AuditLog::where('action', 'contact.restored')
            ->where('auditable_id', $this->contact->id)
            ->first();

        expect($auditLog)->not->toBeNull()
            ->and($auditLog->new_values)->toHaveKey('callsign', 'W1AW');
    });

    test('denies access without edit-contacts permission', function () {
        $regularUser = User::factory()->create();
        $this->actingAs($regularUser);

        Livewire::test(ContactEditor::class)
            ->call('restoreContact', $this->contact->id)
            ->assertForbidden();
    });
});
