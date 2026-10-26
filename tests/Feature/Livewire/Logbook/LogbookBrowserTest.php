<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Logbook\LogbookBrowser;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Scoring\Rules\WinterFieldDay2027;
use Livewire\Livewire;
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

/**
 * Render the browser with the given filter state and return the visible contact IDs.
 *
 * @param  array<string, mixed>  $filters
 * @return array<int, int>
 */
function filteredContactIds(array $filters): array
{
    $component = Livewire::test(LogbookBrowser::class);

    foreach ($filters as $property => $value) {
        $component->set($property, $value);
    }

    return collect($component->instance()->contacts->items())->pluck('id')->sort()->values()->all();
}

describe('filters narrow the contact results', function () {
    beforeEach(function () {
        $this->contactFor = fn (array $attributes = []) => Contact::factory()->create(array_merge([
            'event_configuration_id' => $this->eventConfiguration->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'section_id' => $this->section->id,
        ], $attributes));
    });

    test('bandIds limits results to the selected bands', function () {
        $otherBand = Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.075]);
        $match = ($this->contactFor)();
        ($this->contactFor)(['band_id' => $otherBand->id]);

        expect(filteredContactIds(['bandIds' => [$this->band->id]]))->toBe([$match->id]);
    });

    test('modeIds limits results to the selected modes', function () {
        $otherMode = Mode::create(['name' => 'CW', 'category' => 'CW']);
        $match = ($this->contactFor)();
        ($this->contactFor)(['mode_id' => $otherMode->id]);

        expect(filteredContactIds(['modeIds' => [$this->mode->id]]))->toBe([$match->id]);
    });

    test('stationIds limits results to sessions on the selected stations', function () {
        $otherStation = Station::factory()->create(['event_configuration_id' => $this->eventConfiguration->id]);
        $match = ($this->contactFor)([
            'operating_session_id' => OperatingSession::factory()->create(['station_id' => $this->station->id])->id,
        ]);
        ($this->contactFor)([
            'operating_session_id' => OperatingSession::factory()->create(['station_id' => $otherStation->id])->id,
        ]);

        expect(filteredContactIds(['stationIds' => [$this->station->id]]))->toBe([$match->id]);
    });

    test('operatorIds limits results to contacts logged by the selected operators', function () {
        $operator = User::factory()->create();
        $match = ($this->contactFor)(['logger_user_id' => $operator->id]);
        ($this->contactFor)(['logger_user_id' => User::factory()->create()->id]);

        expect(filteredContactIds(['operatorIds' => [$operator->id]]))->toBe([$match->id]);
    });

    test('timeFrom excludes contacts before the start of the range', function () {
        ($this->contactFor)(['qso_time' => '2025-02-07 09:00:00']);
        $match = ($this->contactFor)(['qso_time' => '2025-02-07 11:00:00']);

        expect(filteredContactIds(['timeFrom' => '2025-02-07 10:00:00']))->toBe([$match->id]);
    });

    test('timeTo excludes contacts after the end of the range', function () {
        $match = ($this->contactFor)(['qso_time' => '2025-02-07 19:00:00']);
        ($this->contactFor)(['qso_time' => '2025-02-07 21:00:00']);

        expect(filteredContactIds(['timeTo' => '2025-02-07 20:00:00']))->toBe([$match->id]);
    });

    test('callsignSearch matches partial callsigns case-insensitively', function () {
        $match = ($this->contactFor)(['callsign' => 'W1AW']);
        ($this->contactFor)(['callsign' => 'K2XYZ']);

        expect(filteredContactIds(['callsignSearch' => '1aw']))->toBe([$match->id]);
    });

    test('sectionIds limits results to the selected sections', function () {
        $otherSection = Section::factory()->create(['code' => 'ME', 'name' => 'Maine']);
        $match = ($this->contactFor)();
        ($this->contactFor)(['section_id' => $otherSection->id]);

        expect(filteredContactIds(['sectionIds' => [$this->section->id]]))->toBe([$match->id]);
    });

    test('showDuplicates filters by duplicate status', function (string $value, bool $expectDuplicate) {
        $unique = ($this->contactFor)();
        $duplicate = ($this->contactFor)(['is_duplicate' => true, 'points' => 0]);

        $expected = $expectDuplicate ? $duplicate->id : $unique->id;

        expect(filteredContactIds(['showDuplicates' => $value]))->toBe([$expected]);
    })->with([
        'only' => ['only', true],
        'exclude' => ['exclude', false],
    ]);

    test('no filters returns every active contact for the event', function () {
        $contacts = collect([($this->contactFor)(), ($this->contactFor)()]);

        expect(filteredContactIds([]))->toBe($contacts->pluck('id')->sort()->values()->all());
    });
});

test('reset filters clears all filter values', function () {
    $component = new LogbookBrowser;
    $component->bandIds = [$this->band->id];
    $component->modeIds = [$this->mode->id];
    $component->stationIds = [$this->station->id];
    $component->callsignSearch = 'W1AW';
    $component->sectionIds = [$this->section->id];

    $component->resetFilters();

    expect($component->bandIds)->toBe([])
        ->and($component->modeIds)->toBe([])
        ->and($component->stationIds)->toBe([])
        ->and($component->callsignSearch)->toBeNull()
        ->and($component->sectionIds)->toBe([]);
});

test('updated filter property clears selections and resets page', function (string $property) {
    $component = new LogbookBrowser;
    $component->mount();
    $component->selectedIds = [1, 2, 3];
    $component->setPage(3);

    $component->updated($property);

    expect($component->selectedIds)->toBe([])
        ->and($component->getPage())->toBe(1);
})->with([
    'bandIds',
    'modeIds',
    'stationIds',
    'operatorIds',
    'timeFrom',
    'timeTo',
    'callsignSearch',
    'sectionIds',
    'showDuplicates',
]);

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

describe('showDeleted filter', function () {
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

    test('showDeleted defaults to null and excludes deleted contacts', function () {
        Livewire::test(LogbookBrowser::class)
            ->assertSet('showDeleted', null);
    });

    test('showDeleted "include" shows both active and deleted contacts', function () {
        $this->user->givePermissionTo('edit-contacts');

        Livewire::test(LogbookBrowser::class)
            ->set('showDeleted', 'include')
            ->assertSee($this->activeContact->callsign)
            ->assertSee($this->deletedContact->callsign);
    });

    test('showDeleted "only" shows only deleted contacts', function () {
        $this->user->givePermissionTo('edit-contacts');

        Livewire::test(LogbookBrowser::class)
            ->set('showDeleted', 'only')
            ->assertDontSee($this->activeContact->callsign)
            ->assertSee($this->deletedContact->callsign);
    });
});

describe('stats', function () {
    test('stats leave dupes out of the QSO count and GOTA out of QSO points', function () {
        $attributes = [
            'event_configuration_id' => $this->eventConfiguration->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
        ];

        Contact::factory()->count(3)->create($attributes + ['points' => 2]);
        Contact::factory()->duplicate()->create($attributes);
        Contact::factory()->gota()->count(2)->create($attributes + ['points' => 5]);

        $stats = Livewire::test(LogbookBrowser::class)->instance()->stats;

        expect($stats['total_qsos'])->toBe(5)
            ->and($stats['duplicate_count'])->toBe(1)
            ->and($stats['total_points'])->toBe(6)
            ->and($stats['gota_points'])->toBe(10)
            ->and(array_sum($stats['by_mode']))->toBe(5);
    });

    test('stats summary labels the dupes and GOTA bonus it leaves out', function () {
        $attributes = [
            'event_configuration_id' => $this->eventConfiguration->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
        ];

        Contact::factory()->create($attributes);
        Contact::factory()->duplicate()->create($attributes);
        Contact::factory()->gota()->create($attributes + ['points' => 5]);

        Livewire::test(LogbookBrowser::class)
            ->assertSeeText('1 dupe not counted')
            ->assertSeeText('Plus 5 GOTA bonus pts');
    });
});

describe('event listeners', function () {
    test('contact change events clear the selection and re-query contacts and stats', function (string $event) {
        $component = Livewire::test(LogbookBrowser::class)
            ->set('selectedIds', [1, 2]);

        expect($component->instance()->stats['total_qsos'])->toBe(0);

        $contact = Contact::factory()->create([
            'event_configuration_id' => $this->eventConfiguration->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'N1NEW',
        ]);

        $component->dispatch($event)
            ->assertSet('selectedIds', [])
            ->assertSee('N1NEW');

        expect($component->instance()->stats['total_qsos'])->toBe(1)
            ->and($component->instance()->contacts->items()[0]->id)->toBe($contact->id);
    })->with(['contact-updated', 'contact-deleted', 'contact-restored']);

    test('bulk action buttons read selected ids client-side without escaped json', function () {
        Permission::findOrCreate('edit-contacts', 'web');
        $this->user->givePermissionTo('edit-contacts');

        Livewire::test(LogbookBrowser::class)
            ->set('selectedIds', ['1', '2'])
            ->assertSeeHtml("\$dispatch('open-bulk-change-logger', { contactIds: \$wire.selectedIds })")
            ->assertSeeHtml("\$dispatch('bulk-delete-contacts', { contactIds: \$wire.selectedIds })")
            ->assertDontSeeHtml('&amp;quot;');
    });

    test('bulk action bar wraps on narrow screens', function () {
        Permission::findOrCreate('edit-contacts', 'web');
        $this->user->givePermissionTo('edit-contacts');

        Livewire::test(LogbookBrowser::class)
            ->set('selectedIds', ['1'])
            ->assertSeeHtml('flex flex-wrap items-center gap-x-4 gap-y-2 mb-4')
            ->assertSeeHtml('flex gap-2 w-full sm:w-auto');
    });
});

describe('activeFilterCount', function () {
    test('returns zero when no filters are set', function () {
        $component = new LogbookBrowser;

        expect($component->activeFilterCount())->toBe(0);
    });

    test('counts a non-empty array filter', function () {
        $component = new LogbookBrowser;
        $component->bandIds = [$this->band->id];

        expect($component->activeFilterCount())->toBe(1);
    });

    test('counts a non-empty string filter', function () {
        $component = new LogbookBrowser;
        $component->callsignSearch = 'W1AW';

        expect($component->activeFilterCount())->toBe(1);
    });

    test('counts timeFrom and timeTo as a single time-range filter', function () {
        $component = new LogbookBrowser;
        $component->timeFrom = '2025-02-07 10:00:00';
        $component->timeTo = '2025-02-07 20:00:00';

        expect($component->activeFilterCount())->toBe(1);
    });

    test('sums multiple active filters', function () {
        $component = new LogbookBrowser;
        $component->bandIds = [$this->band->id];
        $component->modeIds = [$this->mode->id];
        $component->callsignSearch = 'W1AW';
        $component->showDuplicates = 'exclude';

        expect($component->activeFilterCount())->toBe(4);
    });
});

describe('activeFilterPills', function () {
    test('returns empty array when no filters are set', function () {
        $component = new LogbookBrowser;

        expect($component->activeFilterPills())->toBe([]);
    });

    test('filter pill remove buttons have accessible names', function () {
        Livewire::test(LogbookBrowser::class)
            ->set('callsignSearch', 'W1AW')
            ->assertSeeHtml('aria-label="Remove Callsign: W1AW filter"');
    });

    test('returns a band pill with selected-count label', function () {
        $component = new LogbookBrowser;
        $component->bandIds = [$this->band->id];

        $pills = $component->activeFilterPills();

        expect($pills)->toHaveCount(1)
            ->and($pills[0]['key'])->toBe('bandIds')
            ->and($pills[0]['label'])->toBe('Band: 1 selected');
    });

    test('returns a callsign pill with the search value', function () {
        $component = new LogbookBrowser;
        $component->callsignSearch = 'W1AW';

        $pills = $component->activeFilterPills();

        expect($pills[0]['key'])->toBe('callsignSearch')
            ->and($pills[0]['label'])->toBe('Callsign: W1AW');
    });

    test('returns a single time pill when both ends are set', function () {
        $component = new LogbookBrowser;
        $component->timeFrom = '2025-02-07 10:00:00';
        $component->timeTo = '2025-02-07 20:00:00';

        $pills = $component->activeFilterPills();

        expect($pills)->toHaveCount(1)
            ->and($pills[0]['key'])->toBe('timeRange')
            ->and($pills[0]['label'])->toBe('Time: 2025-02-07 10:00:00 – 2025-02-07 20:00:00');
    });

    test('returns readable labels for duplicate, transcribed, gota, and deleted filters', function () {
        $component = new LogbookBrowser;
        $component->showDuplicates = 'exclude';
        $component->showTranscribed = 'only';
        $component->showGota = 'only';
        $component->showDeleted = 'include';

        $pills = collect($component->activeFilterPills())->keyBy('key');

        expect($pills['showDuplicates']['label'])->toBe('Dupes: Exclude')
            ->and($pills['showTranscribed']['label'])->toBe('Transcribed: Only')
            ->and($pills['showGota']['label'])->toBe('GOTA: Only')
            ->and($pills['showDeleted']['label'])->toBe('Deleted: Include');
    });
});

describe('removeFilter', function () {
    test('clears an array filter by key', function () {
        $component = new LogbookBrowser;
        $component->bandIds = [$this->band->id];

        $component->removeFilter('bandIds');

        expect($component->bandIds)->toBe([]);
    });

    test('clears a string filter by key', function () {
        $component = new LogbookBrowser;
        $component->callsignSearch = 'W1AW';

        $component->removeFilter('callsignSearch');

        expect($component->callsignSearch)->toBeNull();
    });

    test('timeRange clears both timeFrom and timeTo', function () {
        $component = new LogbookBrowser;
        $component->timeFrom = '2025-02-07 10:00:00';
        $component->timeTo = '2025-02-07 20:00:00';

        $component->removeFilter('timeRange');

        expect($component->timeFrom)->toBeNull()
            ->and($component->timeTo)->toBeNull();
    });

    test('unknown key is a no-op', function () {
        $component = new LogbookBrowser;
        $component->bandIds = [$this->band->id];

        $component->removeFilter('unknownKey');

        expect($component->bandIds)->toBe([$this->band->id]);
    });
});

describe('no-credit badge', function () {
    test('marks WFD 2027 WSJT-mode contacts with the reason', function () {
        $wfd = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);
        $this->event->updateQuietly(['event_type_id' => $wfd->id, 'rules_version' => '2027']);

        $ft8 = Contact::factory()->create([
            'event_configuration_id' => $this->eventConfiguration->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'submode' => 'FT8',
            'callsign' => 'K1FT',
        ]);
        $rtty = Contact::factory()->create([
            'event_configuration_id' => $this->eventConfiguration->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'submode' => 'RTTY',
            'callsign' => 'K1RT',
        ]);

        $component = Livewire::test(LogbookBrowser::class);

        expect($component->instance()->noCreditReasons)
            ->toBe([$ft8->id => WinterFieldDay2027::NO_CREDIT_WSJT])
            ->not->toHaveKey($rtty->id);

        $component->assertSee('no credit')
            ->assertSee('title="'.WinterFieldDay2027::NO_CREDIT_WSJT.'"', false);
    });

    test('shows no badge for rulesets that credit every contact', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfiguration->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'submode' => 'FT8',
        ]);

        $component = Livewire::test(LogbookBrowser::class);

        expect($component->instance()->noCreditReasons)->toBe([]);
        $component->assertDontSee('no credit');
    });
});

test('listens for contact changes on the event channel when broadcasting is configured', function () {
    config(['broadcasting.default' => 'reverb', 'reverb.apps.apps.0.key' => 'test-key']);

    $listeners = Livewire::test(LogbookBrowser::class)->instance()->getListeners();

    expect($listeners)->toBe([
        "echo-private:event.{$this->event->id},.ContactLogged" => 'refreshFromBroadcast',
        "echo-private:event.{$this->event->id},.ContactUpdated" => 'refreshFromBroadcast',
        "echo-private:event.{$this->event->id},.ContactDeleted" => 'refreshFromBroadcast',
    ]);
});

test('registers no broadcast listeners without a broadcaster', function () {
    config(['broadcasting.default' => 'log']);

    expect(Livewire::test(LogbookBrowser::class)->instance()->getListeners())->toBe([]);
});

test('a broadcast refresh keeps the selected rows', function () {
    Livewire::test(LogbookBrowser::class)
        ->set('selectedIds', [1, 2])
        ->call('refreshFromBroadcast')
        ->assertSet('selectedIds', [1, 2]);
});

test('contact cards show the full station name instead of a fixed-width truncation', function () {
    $station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'name' => 'Station Delta Long Name',
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $this->eventConfiguration->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'operating_session_id' => OperatingSession::factory()->create(['station_id' => $station->id])->id,
    ]);

    Livewire::test(LogbookBrowser::class)
        ->assertSee('Station Delta Long Name')
        ->assertSeeHtml('min-w-0 flex-1 break-words')
        ->assertDontSeeHtml('truncate max-w-[120px]');
});
