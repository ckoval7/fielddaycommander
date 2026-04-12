<?php

use App\Livewire\Logbook\ContactEditor;
use App\Livewire\Logbook\LogbookBrowser;
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

    $this->editor = User::factory()->create();
    $this->editor->givePermissionTo('edit-contacts');

    $this->viewer = User::factory()->create();

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
        'qso_count' => 3,
    ]);
});

test('user without edit-contacts cannot see actions column', function () {
    $this->actingAs($this->viewer);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    Livewire::test(LogbookBrowser::class)
        ->assertDontSee('Actions');
});

test('user with edit-contacts can see actions column', function () {
    $this->actingAs($this->editor);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    Livewire::test(LogbookBrowser::class)
        ->assertSee('Actions');
});

test('user without edit-contacts cannot see deleted filter', function () {
    $this->actingAs($this->viewer);

    Livewire::test(LogbookBrowser::class)
        ->assertDontSee('Deleted Status');
});

test('user with edit-contacts can see deleted filter', function () {
    $this->actingAs($this->editor);

    Livewire::test(LogbookBrowser::class)
        ->assertSee('Deleted Status');
});

test('full delete and restore cycle updates qso_count correctly', function () {
    $this->actingAs($this->editor);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
    ]);

    // Delete
    Livewire::test(ContactEditor::class)
        ->call('deleteContact', $contact->id);

    $this->session->refresh();
    expect($this->session->qso_count)->toBe(2)
        ->and($contact->fresh()->trashed())->toBeTrue();

    // Restore
    Livewire::test(ContactEditor::class)
        ->call('restoreContact', $contact->id);

    $this->session->refresh();
    expect($this->session->qso_count)->toBe(3)
        ->and($contact->fresh()->trashed())->toBeFalse();
});

test('duplicate detection runs on save and updates points', function () {
    $this->actingAs($this->editor);

    // Create an existing contact
    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'callsign' => 'K1ABC',
        'received_exchange' => 'K1ABC 3A CT',
        'is_duplicate' => false,
        'points' => 1,
    ]);

    // Create a second contact that we'll edit to match
    $editableContact = Contact::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'callsign' => 'W9XYZ',
        'received_exchange' => 'W9XYZ 2A CT',
        'is_duplicate' => false,
        'points' => 1,
    ]);

    // Edit to match existing contact's callsign — should become duplicate
    Livewire::test(ContactEditor::class)
        ->call('openEdit', $editableContact->id)
        ->set('callsign', 'K1ABC')
        ->set('received_exchange', 'K1ABC 3A CT')
        ->call('save');

    $editableContact->refresh();
    expect($editableContact->is_duplicate)->toBeTrue()
        ->and($editableContact->points)->toBe(0);
});
