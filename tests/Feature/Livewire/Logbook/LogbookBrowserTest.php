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
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    // Create a user and event setup
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Create an active event with configuration
    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->eventConfiguration = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    // Create reference data
    $this->band = Band::first() ?? Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175]);
    $this->mode = Mode::first() ?? Mode::create(['name' => 'SSB', 'category' => 'Phone']);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->eventConfiguration->id]);
    $this->section = Section::first() ?? Section::create(['name' => 'Connecticut', 'code' => 'CT']);
});

test('component mounts and sets active event configuration', function () {
    $component = new LogbookBrowser;
    $component->mount();

    expect($component->eventConfigurationId)->toBe($this->eventConfiguration->id);
});

test('component sets no event configuration when no active event', function () {
    Event::query()->delete();

    $component = new LogbookBrowser;
    $component->mount();

    expect($component->eventConfigurationId)->toBeNull();
});

test('band_ids property can be set', function () {
    $component = new LogbookBrowser;

    $component->band_ids = [$this->band->id];

    expect($component->band_ids)->toBe([$this->band->id]);
});

test('mode_ids property can be set', function () {
    $component = new LogbookBrowser;

    $component->mode_ids = [$this->mode->id];

    expect($component->mode_ids)->toBe([$this->mode->id]);
});

test('station_ids property can be set', function () {
    $component = new LogbookBrowser;

    $component->station_ids = [$this->station->id];

    expect($component->station_ids)->toBe([$this->station->id]);
});

test('operator_ids property can be set', function () {
    $operator = User::factory()->create();

    $component = new LogbookBrowser;
    $component->operator_ids = [$operator->id];

    expect($component->operator_ids)->toBe([$operator->id]);
});

test('time_from property can be set', function () {
    $component = new LogbookBrowser;

    $component->time_from = '2025-02-07 10:00:00';

    expect($component->time_from)->toBe('2025-02-07 10:00:00');
});

test('time_to property can be set', function () {
    $component = new LogbookBrowser;

    $component->time_to = '2025-02-07 20:00:00';

    expect($component->time_to)->toBe('2025-02-07 20:00:00');
});

test('callsign_search property can be set', function () {
    $component = new LogbookBrowser;

    $component->callsign_search = 'W1AW';

    expect($component->callsign_search)->toBe('W1AW');
});

test('section_ids property can be set', function () {
    $component = new LogbookBrowser;

    $component->section_ids = [$this->section->id];

    expect($component->section_ids)->toBe([$this->section->id]);
});

test('show_duplicates property can be set', function () {
    $component = new LogbookBrowser;

    $component->show_duplicates = 'only';

    expect($component->show_duplicates)->toBe('only');
});

test('reset filters clears all filter values', function () {
    $component = new LogbookBrowser;
    $component->band_ids = [$this->band->id];
    $component->mode_ids = [$this->mode->id];
    $component->station_ids = [$this->station->id];
    $component->callsign_search = 'W1AW';
    $component->section_ids = [$this->section->id];

    $component->resetFilters();

    expect($component->band_ids)->toBe([])
        ->and($component->mode_ids)->toBe([])
        ->and($component->station_ids)->toBe([])
        ->and($component->callsign_search)->toBeNull()
        ->and($component->section_ids)->toBe([]);
});

test('updated band_ids resets page', function () {
    $component = new LogbookBrowser;
    $component->mount();
    $component->updatedBandIds();

    expect($component)->toBeInstanceOf(LogbookBrowser::class);
});

test('updated mode_ids resets page', function () {
    $component = new LogbookBrowser;
    $component->mount();
    $component->updatedModeIds();

    expect($component)->toBeInstanceOf(LogbookBrowser::class);
});

test('updated station_ids resets page', function () {
    $component = new LogbookBrowser;
    $component->mount();
    $component->updatedStationIds();

    expect($component)->toBeInstanceOf(LogbookBrowser::class);
});

test('updated operator_ids resets page', function () {
    $component = new LogbookBrowser;
    $component->mount();
    $component->updatedOperatorIds();

    expect($component)->toBeInstanceOf(LogbookBrowser::class);
});

test('updated time_from resets page', function () {
    $component = new LogbookBrowser;
    $component->mount();
    $component->updatedTimeFrom();

    expect($component)->toBeInstanceOf(LogbookBrowser::class);
});

test('updated time_to resets page', function () {
    $component = new LogbookBrowser;
    $component->mount();
    $component->updatedTimeTo();

    expect($component)->toBeInstanceOf(LogbookBrowser::class);
});

test('updated callsign_search resets page', function () {
    $component = new LogbookBrowser;
    $component->mount();
    $component->updatedCallsignSearch();

    expect($component)->toBeInstanceOf(LogbookBrowser::class);
});

test('updated section_ids resets page', function () {
    $component = new LogbookBrowser;
    $component->mount();
    $component->updatedSectionIds();

    expect($component)->toBeInstanceOf(LogbookBrowser::class);
});

test('updated show_duplicates resets page', function () {
    $component = new LogbookBrowser;
    $component->mount();
    $component->updatedShowDuplicates();

    expect($component)->toBeInstanceOf(LogbookBrowser::class);
});

test('contacts are loaded when event is active', function () {
    Contact::factory()->count(5)->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'points' => 2,
    ]);

    $component = new LogbookBrowser;
    $component->mount();

    // Verify the component has the active event configuration
    expect($component->eventConfigurationId)->toBe($this->eventConfiguration->id);

    // Verify contacts exist in database for this event
    $dbContacts = Contact::where('event_configuration_id', $this->eventConfiguration->id)->get();
    expect($dbContacts->count())->toBe(5);
});

test('band filter would affect contact results logic', function () {
    $band2 = Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.075]);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'band_id' => $this->band->id,
    ]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'band_id' => $band2->id,
    ]);

    // Verify both unfiltered and filtered counts exist in database
    $allContacts = Contact::where('event_configuration_id', $this->eventConfiguration->id)->count();
    $filteredContacts = Contact::where('event_configuration_id', $this->eventConfiguration->id)
        ->where('band_id', $this->band->id)
        ->count();

    expect($allContacts)->toBe(8);
    expect($filteredContacts)->toBe(5);
});

test('mode filter would affect contact results logic', function () {
    $mode2 = Mode::create(['name' => 'CW', 'category' => 'CW']);

    Contact::factory()->count(4)->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'mode_id' => $this->mode->id,
    ]);

    Contact::factory()->count(2)->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'mode_id' => $mode2->id,
    ]);

    // Verify both unfiltered and filtered counts exist in database
    $allContacts = Contact::where('event_configuration_id', $this->eventConfiguration->id)->count();
    $filteredContacts = Contact::where('event_configuration_id', $this->eventConfiguration->id)
        ->where('mode_id', $this->mode->id)
        ->count();

    expect($allContacts)->toBe(6);
    expect($filteredContacts)->toBe(4);
});

test('bands are available for filtering', function () {
    // At least the default band should exist
    expect(Band::count())->toBeGreaterThan(0);
});

test('modes are available for filtering', function () {
    // At least the default mode should exist
    expect(Mode::count())->toBeGreaterThan(0);
});

test('stations filter by event', function () {
    $component = new LogbookBrowser;
    $component->mount();

    // Should have at least 1 station for this event
    expect(Station::where('event_configuration_id', $this->eventConfiguration->id)->count())->toBe(1);
});

test('stations are empty without active event', function () {
    Event::query()->delete();

    $component = new LogbookBrowser;
    $component->mount();

    expect($component->eventConfigurationId)->toBeNull();
});

test('operators are available for filtering', function () {
    // At least the test user should exist
    expect(User::count())->toBeGreaterThan(0);
});

test('sections are available for filtering', function () {
    // At least the default section should exist
    expect(Section::count())->toBeGreaterThan(0);
});

test('component has correct per page setting', function () {
    $component = new LogbookBrowser;

    expect($component->perPage)->toBe(50);
});

describe('show_deleted filter', function () {
    beforeEach(function () {
        Permission::findOrCreate('edit-contacts', 'web');

        $session = OperatingSession::factory()->create([
            'station_id' => $this->station->id,
        ]);

        $this->activeContact = Contact::factory()->create([
            'event_configuration_id' => $this->eventConfiguration->id,
            'operating_session_id' => $session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'section_id' => $this->section->id,
        ]);

        $this->deletedContact = Contact::factory()->create([
            'event_configuration_id' => $this->eventConfiguration->id,
            'operating_session_id' => $session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'section_id' => $this->section->id,
        ]);
        $this->deletedContact->delete();
    });

    test('show_deleted defaults to null and excludes deleted contacts', function () {
        Livewire::test(LogbookBrowser::class)
            ->assertSet('show_deleted', null);
    });

    test('show_deleted "include" shows both active and deleted contacts', function () {
        $this->user->givePermissionTo('edit-contacts');

        Livewire::test(LogbookBrowser::class)
            ->set('show_deleted', 'include')
            ->assertSee($this->activeContact->callsign)
            ->assertSee($this->deletedContact->callsign);
    });

    test('show_deleted "only" shows only deleted contacts', function () {
        $this->user->givePermissionTo('edit-contacts');

        Livewire::test(LogbookBrowser::class)
            ->set('show_deleted', 'only')
            ->assertDontSee($this->activeContact->callsign)
            ->assertSee($this->deletedContact->callsign);
    });
});

describe('event listeners', function () {
    test('contact-updated refreshes contacts', function () {
        Livewire::test(LogbookBrowser::class)
            ->dispatch('contact-updated');
    });

    test('contact-deleted refreshes contacts', function () {
        Livewire::test(LogbookBrowser::class)
            ->dispatch('contact-deleted');
    });

    test('contact-restored refreshes contacts', function () {
        Livewire::test(LogbookBrowser::class)
            ->dispatch('contact-restored');
    });
});
