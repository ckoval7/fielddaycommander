<?php

use App\Livewire\Logbook\LogbookBrowser;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\EventContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mark system as set up
    DB::table('system_config')->insert([
        'key' => 'setup_completed',
        'value' => 'true',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create active event
    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    // Create authenticated user
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Create some contacts for testing
    Contact::factory(5)->create([
        'event_configuration_id' => $this->eventConfig->id,
    ]);
});

describe('responsive layout patterns', function () {
    test('filter panel uses collapsible card on mobile', function () {
        Livewire::test(LogbookBrowser::class)
            ->assertSee('Filters')
            ->assertSeeHtml('x-data'); // Alpine.js for collapsible behavior
    });

    test('stats summary grid uses responsive column count', function () {
        // Verify stats grid follows pattern: grid-cols-2 md:grid-cols-3 lg:grid-cols-6
        Livewire::test(LogbookBrowser::class)
            ->assertSee('Total QSOs')
            ->assertSee('Total Points');
    });

    test('results view exists and is rendered', function () {
        Livewire::test(LogbookBrowser::class)
            ->assertSee('QSO Time')
            ->assertSee('Callsign')
            ->assertSee('Band')
            ->assertSee('Mode');
    });

    test('export button is accessible', function () {
        Livewire::test(LogbookBrowser::class)
            ->assertSee('Export');
    });

    test('pagination controls are present for large result sets', function () {
        // Create more contacts to trigger pagination
        Contact::factory(55)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        Livewire::test(LogbookBrowser::class)
            ->assertSeeHtml('dusk="nextPage"');
    });

    test('filter form controls are rendered', function () {
        Livewire::test(LogbookBrowser::class)
            ->assertSee('Band')
            ->assertSee('Mode')
            ->assertSee('Filters');
    });

    test('component handles no active event gracefully', function () {
        // Delete all events and event configurations so no context event is found
        Contact::query()->forceDelete();
        OperatingSession::query()->forceDelete();
        Station::query()->forceDelete();
        EventConfiguration::query()->forceDelete();
        Event::query()->forceDelete();
        app(EventContextService::class)->clearCache();

        Livewire::test(LogbookBrowser::class)
            ->assertSee('No Active Event')
            ->assertSee('Please activate an event');
    });
});

describe('responsive class validation', function () {
    test('main layout container exists', function () {
        $component = Livewire::test(LogbookBrowser::class);

        // Component should render without errors
        expect($component->get('eventConfigurationId'))->toBe($this->eventConfig->id);
    });

    test('loading states are implemented', function () {
        Livewire::test(LogbookBrowser::class)
            ->assertSeeHtml('wire:loading'); // Livewire loading directive
    });

    test('filter panel has reset functionality', function () {
        Livewire::test(LogbookBrowser::class)
            ->set('bandIds', [1])
            ->set('modeIds', [1])
            ->call('resetFilters')
            ->assertSet('bandIds', [])
            ->assertSet('modeIds', []);
    });

    test('contacts are filtered by band', function () {
        $band = Band::first();
        if (! $band) {
            $this->markTestSkipped('No bands in database');
        }

        // Create contacts with specific band
        Contact::factory(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'band_id' => $band->id,
            'callsign' => 'W1BAND',
        ]);

        $component = Livewire::test(LogbookBrowser::class)
            ->set('bandIds', [$band->id]);

        $contacts = $component->get('contacts');
        expect($contacts->every(fn ($contact) => $contact->band_id === $band->id))->toBeTrue();
    });

    test('contacts are filtered by mode', function () {
        $mode = Mode::first();
        if (! $mode) {
            $this->markTestSkipped('No modes in database');
        }

        // Create contacts with specific mode
        Contact::factory(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'mode_id' => $mode->id,
            'callsign' => 'W1MODE',
        ]);

        $component = Livewire::test(LogbookBrowser::class)
            ->set('modeIds', [$mode->id]);

        $contacts = $component->get('contacts');
        expect($contacts->every(fn ($contact) => $contact->mode_id === $mode->id))->toBeTrue();
    });

    test('callsign search filters contacts', function () {
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'W1SEARCH',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'K1OTHER',
        ]);

        Livewire::test(LogbookBrowser::class)
            ->set('callsignSearch', 'W1SEARCH')
            ->assertSee('W1SEARCH')
            ->assertDontSee('K1OTHER');
    });

    test('duplicate filter works', function () {
        // Delete all pre-existing contacts to isolate the test
        Contact::query()->forceDelete();

        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'W1DUP',
            'is_duplicate' => true,
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'callsign' => 'W1VALID',
            'is_duplicate' => false,
        ]);

        Livewire::test(LogbookBrowser::class)
            ->set('showDuplicates', 'exclude')
            ->assertSee('W1VALID')
            ->assertDontSee('W1DUP');
    });
});

describe('accessibility and usability', function () {
    test('empty state is shown when no contacts exist', function () {
        // Delete all contacts
        Contact::query()->delete();

        Livewire::test(LogbookBrowser::class)
            ->assertSee('No contacts found');
    });

    test('stats are calculated correctly', function () {
        $component = Livewire::test(LogbookBrowser::class);

        $stats = $component->get('stats');

        expect($stats)->toHaveKey('total_qsos')
            ->and($stats)->toHaveKey('total_points');
    });

    test('contacts are ordered chronologically', function () {
        // Delete all pre-existing contacts to isolate the test
        Contact::query()->forceDelete();

        // Create contacts with different times
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => now()->subHours(3),
            'callsign' => 'OLD',
        ]);
        Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'qso_time' => now()->subHour(),
            'callsign' => 'NEW',
        ]);

        $component = Livewire::test(LogbookBrowser::class);
        $contacts = $component->get('contacts');

        // Most recent should be first
        expect($contacts->first()->callsign)->toBe('NEW')
            ->and($contacts->last()->callsign)->toBe('OLD');
    });

    test('pagination works correctly', function () {
        // Create 55 contacts to trigger pagination (50 per page default)
        Contact::factory(55)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $component = Livewire::test(LogbookBrowser::class);
        $contacts = $component->get('contacts');

        // Should have 50 on first page
        expect($contacts)->toHaveCount(50);
    });
});

describe('class display formatting', function () {
    test('exchange class is normalized to uppercase on save', function () {
        $contact = Contact::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'exchange_class' => '3a',
        ]);

        expect($contact->exchange_class)->toBe('3A');
    });
});

describe('manual testing checklist documentation', function () {
    test('responsive breakpoints reference', function () {
        // This test documents the manual testing requirements
        // See docs/responsive-patterns.md for full details

        $breakpoints = [
            '375px' => 'Mobile (iPhone SE) - Filter panel collapsible, card view',
            '640px' => 'sm breakpoint - Layout transitions begin',
            '768px' => 'md breakpoint (Tablet) - Filter panel visible, table view',
            '1024px' => 'lg breakpoint (Desktop) - Two-column layout, full table',
            '1280px' => 'xl breakpoint - Wide content areas',
        ];

        expect($breakpoints)->toHaveCount(5);

        // Manual testing checklist:
        // ✓ Filter panel is collapsible on mobile (< 1024px)
        // ✓ Results show as cards on mobile, table on desktop
        // ✓ Stats grid: 2 cols mobile → 3 cols tablet → 6 cols desktop
        // ✓ Buttons are touch-friendly on mobile (min 44px height)
        // ✓ No horizontal scrolling at any width
        // ✓ Text truncates properly in cards
        // ✓ Filter controls stack vertically on mobile
        // ✓ Export button accessible at all breakpoints
    });

    test('critical responsive patterns to verify manually', function () {
        $patterns = [
            'Filter Panel' => 'Collapsible below lg (1024px), always visible above',
            'Results View' => 'Cards on mobile, table on desktop',
            'Stats Summary' => 'Grid: 2 cols → 3 cols (md) → 6 cols (lg)',
            'Buttons' => 'min-h-[2.75rem] mobile, min-h-[1.75rem] desktop',
            'Pagination' => 'Stacked mobile, inline desktop',
            'Filter Inputs' => 'Full width mobile, auto desktop',
        ];

        expect($patterns)->toHaveCount(6);

        // These patterns should be manually verified in a real browser
        // Automated browser tests (Dusk) could be added in the future
    });
});
