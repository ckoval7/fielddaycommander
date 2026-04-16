<?php

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
