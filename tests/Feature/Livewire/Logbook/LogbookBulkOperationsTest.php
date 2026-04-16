<?php

use App\Livewire\Logbook\ContactEditor;
use App\Livewire\Logbook\LogbookBrowser;
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
    $this->mode = Mode::first() ?? Mode::create(['name' => 'SSB', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::where('is_active', true)->first() ?? Section::create(['name' => 'Connecticut', 'code' => 'CT', 'is_active' => true]);

    $this->station = Station::factory()->create(['event_configuration_id' => $this->eventConfig->id]);
    $this->session = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'qso_count' => 5,
    ]);

    $this->contacts = Contact::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);
});

describe('selection state', function () {
    test('selectedIds defaults to empty array', function () {
        Livewire::test(LogbookBrowser::class)
            ->assertSet('selectedIds', []);
    });

    test('deselectAll clears selectedIds', function () {
        Livewire::test(LogbookBrowser::class)
            ->set('selectedIds', $this->contacts->pluck('id')->toArray())
            ->call('deselectAll')
            ->assertSet('selectedIds', []);
    });

    test('selectedIds clears on filter change', function () {
        Livewire::test(LogbookBrowser::class)
            ->set('selectedIds', $this->contacts->pluck('id')->toArray())
            ->set('callsignSearch', 'W1AW')
            ->assertSet('selectedIds', []);
    });

    test('selectedIds clears on contact-deleted event', function () {
        Livewire::test(LogbookBrowser::class)
            ->set('selectedIds', $this->contacts->pluck('id')->toArray())
            ->dispatch('contact-deleted')
            ->assertSet('selectedIds', []);
    });

    test('selectedIds clears on contact-updated event', function () {
        Livewire::test(LogbookBrowser::class)
            ->set('selectedIds', $this->contacts->pluck('id')->toArray())
            ->dispatch('contact-updated')
            ->assertSet('selectedIds', []);
    });
});

describe('bulk delete', function () {
    test('bulk deletes multiple contacts and decrements qso_count', function () {
        $contactIds = $this->contacts->pluck('id')->toArray();

        Livewire::test(ContactEditor::class)
            ->call('bulkDeleteContacts', $contactIds)
            ->assertDispatched('contact-deleted')
            ->assertDispatched('notify');

        foreach ($this->contacts as $contact) {
            expect($contact->fresh()->trashed())->toBeTrue();
        }

        $this->session->refresh();
        expect($this->session->qso_count)->toBe(2); // 5 - 3
    });

    test('bulk delete creates audit log for each contact', function () {
        $contactIds = $this->contacts->pluck('id')->toArray();

        Livewire::test(ContactEditor::class)
            ->call('bulkDeleteContacts', $contactIds);

        foreach ($this->contacts as $contact) {
            $auditLog = AuditLog::where('action', 'contact.deleted')
                ->where('auditable_id', $contact->id)
                ->first();

            expect($auditLog)->not->toBeNull();
        }
    });

    test('bulk delete skips contacts user cannot delete', function () {
        $regularUser = User::factory()->create();
        $this->actingAs($regularUser);

        $contactIds = $this->contacts->pluck('id')->toArray();

        Livewire::test(ContactEditor::class)
            ->call('bulkDeleteContacts', $contactIds);

        foreach ($this->contacts as $contact) {
            expect($contact->fresh()->trashed())->toBeFalse();
        }

        $this->session->refresh();
        expect($this->session->qso_count)->toBe(5);
    });

    test('bulk delete with empty array does nothing', function () {
        Livewire::test(ContactEditor::class)
            ->call('bulkDeleteContacts', [])
            ->assertNotDispatched('contact-deleted');
    });
});

describe('bulk change logger', function () {
    test('opens bulk logger modal with contact ids', function () {
        $contactIds = $this->contacts->pluck('id')->toArray();

        Livewire::test(ContactEditor::class)
            ->call('openBulkChangeLogger', $contactIds)
            ->assertSet('showBulkLoggerModal', true)
            ->assertSet('bulkLoggerContactIds', $contactIds);
    });

    test('bulk changes logger for multiple contacts', function () {
        $newLogger = User::factory()->create();
        $contactIds = $this->contacts->pluck('id')->toArray();

        Livewire::test(ContactEditor::class)
            ->call('openBulkChangeLogger', $contactIds)
            ->set('bulkLoggerUserId', $newLogger->id)
            ->call('bulkChangeLogger')
            ->assertHasNoErrors()
            ->assertSet('showBulkLoggerModal', false)
            ->assertDispatched('contact-updated')
            ->assertDispatched('notify');

        foreach ($this->contacts as $contact) {
            expect($contact->fresh()->logger_user_id)->toBe($newLogger->id);
        }
    });

    test('bulk change logger creates audit log for each contact', function () {
        $newLogger = User::factory()->create();
        $contactIds = $this->contacts->pluck('id')->toArray();

        Livewire::test(ContactEditor::class)
            ->call('openBulkChangeLogger', $contactIds)
            ->set('bulkLoggerUserId', $newLogger->id)
            ->call('bulkChangeLogger');

        foreach ($this->contacts as $contact) {
            $auditLog = AuditLog::where('action', 'contact.updated')
                ->where('auditable_id', $contact->id)
                ->first();

            expect($auditLog)->not->toBeNull()
                ->and($auditLog->new_values)->toHaveKey('logger_user_id', $newLogger->id);
        }
    });

    test('bulk change logger validates user exists', function () {
        $contactIds = $this->contacts->pluck('id')->toArray();

        Livewire::test(ContactEditor::class)
            ->call('openBulkChangeLogger', $contactIds)
            ->set('bulkLoggerUserId', 99999)
            ->call('bulkChangeLogger')
            ->assertHasErrors(['bulkLoggerUserId']);
    });

    test('bulk change logger skips contacts user cannot update', function () {
        $regularUser = User::factory()->create();
        $this->actingAs($regularUser);

        $newLogger = User::factory()->create();
        $originalLoggerIds = $this->contacts->pluck('logger_user_id')->toArray();
        $contactIds = $this->contacts->pluck('id')->toArray();

        Livewire::test(ContactEditor::class)
            ->call('openBulkChangeLogger', $contactIds)
            ->set('bulkLoggerUserId', $newLogger->id)
            ->call('bulkChangeLogger');

        foreach ($this->contacts as $i => $contact) {
            expect($contact->fresh()->logger_user_id)->toBe($originalLoggerIds[$i]);
        }
    });

    test('bulk change logger requires user selection', function () {
        $contactIds = $this->contacts->pluck('id')->toArray();

        Livewire::test(ContactEditor::class)
            ->call('openBulkChangeLogger', $contactIds)
            ->call('bulkChangeLogger')
            ->assertHasErrors(['bulkLoggerUserId']);
    });
});
