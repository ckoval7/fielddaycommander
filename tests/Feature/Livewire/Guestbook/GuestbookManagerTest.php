<?php

use App\Livewire\Guestbook\GuestbookManager;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\GuestbookEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create permissions
    Permission::create(['name' => 'manage-guestbook']);

    // Create event and configuration
    $this->event = Event::factory()->create([
        'name' => 'Field Day 2026',
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'guestbook_enabled' => true,
    ]);

    // Create users with permissions
    $this->admin = User::factory()->create([
        'first_name' => 'Admin',
        'last_name' => 'User',
    ]);
    $this->admin->givePermissionTo('manage-guestbook');

    $this->regularUser = User::factory()->create([
        'first_name' => 'Regular',
        'last_name' => 'User',
    ]);
});

// =============================================================================
// Access Tests (3 tests)
// =============================================================================

describe('access control', function () {
    test('requires authentication', function () {
        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->assertForbidden();
    });

    test('requires manage-guestbook permission', function () {
        $this->actingAs($this->regularUser);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->assertForbidden();
    });

    test('allows users with manage-guestbook permission', function () {
        $this->actingAs($this->admin);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->assertStatus(200)
            ->assertSee($this->event->name);
    });
});

// =============================================================================
// List Display Tests (3 tests)
// =============================================================================

describe('list display', function () {
    test('displays guestbook entries for the event', function () {
        $this->actingAs($this->admin);

        $entry = GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'callsign' => 'W1AW',
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->assertSee('John Doe')
            ->assertSee('W1AW');
    });

    test('does not show entries from other events', function () {
        $this->actingAs($this->admin);

        // Entry for this event
        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Current',
            'last_name' => 'Event',
        ]);

        // Entry for another event
        $otherEventConfig = EventConfiguration::factory()->create();
        GuestbookEntry::factory()->create([
            'event_configuration_id' => $otherEventConfig->id,
            'first_name' => 'Other',
            'last_name' => 'Event',
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->assertSee('Current Event')
            ->assertDontSee('Other Event');
    });

    test('paginates entries', function () {
        $this->actingAs($this->admin);

        // Create more than one page of entries (25 per page)
        GuestbookEntry::factory()->count(30)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $component = Livewire::test(GuestbookManager::class, ['event' => $this->event]);

        // First page should have 25 entries
        expect($component->get('entries')->count())->toBe(25);
        expect($component->get('entries')->total())->toBe(30);
    });
});

// =============================================================================
// Search and Filter Tests (4 tests)
// =============================================================================

describe('search and filters', function () {
    test('filters entries by search query', function () {
        $this->actingAs($this->admin);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'callsign' => 'W1AW',
        ]);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'callsign' => 'K2XYZ',
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('search', 'W1AW')
            ->assertSee('John Doe')
            ->assertDontSee('Jane Smith');
    });

    test('filters entries by presence type', function () {
        $this->actingAs($this->admin);

        GuestbookEntry::factory()->inPerson()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'In',
            'last_name' => 'Person',
        ]);

        GuestbookEntry::factory()->online()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Online',
            'last_name' => 'User',
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('filterPresence', GuestbookEntry::PRESENCE_TYPE_IN_PERSON)
            ->assertSee('In Person')
            ->assertDontSee('Online User');
    });

    test('filters entries by visitor category', function () {
        $this->actingAs($this->admin);

        GuestbookEntry::factory()->electedOfficial()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Mayor',
            'last_name' => 'Smith',
        ]);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Regular',
            'last_name' => 'Visitor',
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('filterCategory', GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL)
            ->assertSee('Mayor Smith')
            ->assertDontSee('Regular Visitor');
    });

    test('filters entries by verification status', function () {
        $this->actingAs($this->admin);

        GuestbookEntry::factory()->verified()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Verified',
            'last_name' => 'User',
        ]);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Unverified',
            'last_name' => 'User',
            'is_verified' => false,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('filterVerified', 'verified')
            ->assertSee('Verified User')
            ->assertDontSee('Unverified User');
    });
});

// =============================================================================
// Verification Tests (3 tests)
// =============================================================================

describe('entry verification', function () {
    test('opens verify modal for entry', function () {
        $this->actingAs($this->admin);

        $entry = GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
            'is_verified' => false,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->call('openVerifyModal', $entry->id)
            ->assertSet('showVerifyModal', true)
            ->assertSet('editingEntryId', $entry->id)
            ->assertSet('editCategory', GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC)
            ->assertSet('editVerified', false);
    });

    test('updates entry verification', function () {
        $this->actingAs($this->admin);

        $entry = GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
            'is_verified' => false,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('editingEntryId', $entry->id)
            ->set('editCategory', GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL)
            ->set('editVerified', true)
            ->call('saveVerification')
            ->assertSet('showVerifyModal', false)
            ->assertDispatched('toast');

        $entry->refresh();
        expect($entry->is_verified)->toBeTrue();
        expect($entry->visitor_category)->toBe(GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL);
        expect($entry->verified_by)->toBe($this->admin->id);
        expect($entry->verified_at)->not->toBeNull();
    });
});

// =============================================================================
// Bulk Action Tests (3 tests)
// =============================================================================

describe('bulk actions', function () {
    test('selects all visible entries', function () {
        $this->actingAs($this->admin);

        $entries = GuestbookEntry::factory()->count(5)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $component = Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->call('toggleSelectAll');

        expect($component->get('selectedIds'))
            ->toHaveCount(5)
            ->toEqual($entries->pluck('id')->toArray());
    });

    test('bulk verifies selected entries', function () {
        $this->actingAs($this->admin);

        $entries = GuestbookEntry::factory()->count(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_verified' => false,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('selectedIds', $entries->pluck('id')->toArray())
            ->call('bulkVerify')
            ->assertSet('selectedIds', [])
            ->assertDispatched('toast');

        foreach ($entries as $entry) {
            $entry->refresh();
            expect($entry->is_verified)->toBeTrue();
            expect($entry->verified_by)->toBe($this->admin->id);
        }
    });

    test('bulk unverifies selected entries', function () {
        $this->actingAs($this->admin);

        $entries = GuestbookEntry::factory()->verified()->count(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('selectedIds', $entries->pluck('id')->toArray())
            ->call('bulkUnverify')
            ->assertSet('selectedIds', [])
            ->assertDispatched('toast');

        foreach ($entries as $entry) {
            $entry->refresh();
            expect($entry->is_verified)->toBeFalse();
            expect($entry->verified_by)->toBeNull();
            expect($entry->verified_at)->toBeNull();
        }
    });
});

// =============================================================================
// Delete Tests (2 tests)
// =============================================================================

describe('entry deletion', function () {
    test('soft deletes entry', function () {
        $this->actingAs($this->admin);

        $entry = GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('deletingEntryId', $entry->id)
            ->call('deleteEntry')
            ->assertSet('showDeleteModal', false)
            ->assertDispatched('toast');

        // Entry should be soft deleted (not visible in normal queries)
        expect(GuestbookEntry::find($entry->id))->toBeNull();
        // But should still exist with trashed entries
        expect(GuestbookEntry::withTrashed()->find($entry->id))->not->toBeNull();
        // And should have a deleted_at timestamp
        expect(GuestbookEntry::withTrashed()->find($entry->id)->deleted_at)->not->toBeNull();
    });
});

// =============================================================================
// Additional Feature Tests (3 tests)
// =============================================================================

describe('additional features', function () {
    test('clear filters resets all filter values', function () {
        $this->actingAs($this->admin);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('search', 'test')
            ->set('filterPresence', GuestbookEntry::PRESENCE_TYPE_IN_PERSON)
            ->set('filterCategory', GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL)
            ->set('filterVerified', 'verified')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('filterPresence', null)
            ->assertSet('filterCategory', null)
            ->assertSet('filterVerified', null);
    });

    test('entry stats are calculated correctly', function () {
        $this->actingAs($this->admin);

        // Create mix of entries
        GuestbookEntry::factory()->verified()->inPerson()->electedOfficial()->count(2)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        GuestbookEntry::factory()->online()->count(3)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_verified' => false,
        ]);

        $component = Livewire::test(GuestbookManager::class, ['event' => $this->event]);

        $stats = $component->get('entryStats');
        expect($stats['total'])->toBe(5);
        expect($stats['verified'])->toBe(2);
        expect($stats['unverified'])->toBe(3);
        expect($stats['in_person'])->toBe(2);
        expect($stats['online'])->toBe(3);
        expect($stats['bonus_eligible'])->toBe(2);
    });

    test('search resets pagination', function () {
        $this->actingAs($this->admin);

        // Create enough entries to have multiple pages
        GuestbookEntry::factory()->count(30)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $component = Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->call('nextPage') // Go to page 2
            ->set('search', 'test'); // Trigger search

        // Should reset to page 1
        expect($component->get('entries')->currentPage())->toBe(1);
    });
});

// =============================================================================
// Bulk Action Edge Cases (2 tests)
// =============================================================================

describe('bulk action limits', function () {
    test('bulk verify respects 100 entry limit', function () {
        $this->actingAs($this->admin);

        $entries = GuestbookEntry::factory()->count(101)->create([
            'event_configuration_id' => $this->eventConfig->id,
            'is_verified' => false,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('selectedIds', $entries->pluck('id')->toArray())
            ->call('bulkVerify')
            ->assertDispatched('toast');

        // Should not verify any entries due to limit
        expect(GuestbookEntry::where('is_verified', true)->count())->toBe(0);
    });

    test('bulk delete respects 100 entry limit', function () {
        $this->actingAs($this->admin);

        $entries = GuestbookEntry::factory()->count(101)->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->set('selectedIds', $entries->pluck('id')->toArray())
            ->call('bulkDelete')
            ->assertDispatched('toast');

        // Should not delete any entries due to limit
        expect(GuestbookEntry::count())->toBe(101);
    });
});

// =============================================================================
// CSV Export Tests (6 tests)
// =============================================================================

describe('csv export', function () {
    test('exports guestbook entries to CSV', function () {
        $this->actingAs($this->admin);

        $verifier = User::factory()->create([
            'first_name' => 'Admin',
            'last_name' => 'Verifier',
        ]);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'callsign' => 'W1ABC',
            'email' => 'john@example.com',
            'presence_type' => GuestbookEntry::PRESENCE_TYPE_IN_PERSON,
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
            'is_verified' => true,
            'verified_by' => $verifier->id,
        ]);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'callsign' => 'KC1XYZ',
            'email' => 'jane@example.com',
            'presence_type' => GuestbookEntry::PRESENCE_TYPE_ONLINE,
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
            'is_verified' => false,
        ]);

        $component = new GuestbookManager;
        $component->mount($this->event);

        $response = $component->exportCsv();

        expect($response)->toBeInstanceOf(StreamedResponse::class);
        expect($response->headers->get('Content-Type'))->toBe('text/csv');
        expect($response->headers->get('Content-Disposition'))
            ->toContain('guestbook-field-day-2026-'.now()->format('Y-m-d').'.csv');
    });

    test('csv export includes correct headers', function () {
        $this->actingAs($this->admin);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
        ]);

        $component = new GuestbookManager;
        $component->mount($this->event);

        $response = $component->exportCsv();

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $lines = explode("\n", trim($output));
        $header = str_getcsv($lines[0]);

        expect($header)->toBe([
            'Name',
            'Callsign',
            'Email',
            'Category',
            'Presence',
            'Verified',
            'Verified By',
            'Signed At',
        ]);
    });

    test('csv export respects search filter', function () {
        $this->actingAs($this->admin);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'callsign' => 'W1ABC',
        ]);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'callsign' => 'KC1XYZ',
        ]);

        $component = new GuestbookManager;
        $component->mount($this->event);
        $component->search = 'John';

        $response = $component->exportCsv();

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $lines = explode("\n", trim($output));
        expect(count($lines))->toBe(2);
        expect($lines[1])->toContain('John Doe');
        expect($lines[1])->not->toContain('Jane Smith');
    });

    test('csv export respects presence filter', function () {
        $this->actingAs($this->admin);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'In',
            'last_name' => 'Person',
            'presence_type' => GuestbookEntry::PRESENCE_TYPE_IN_PERSON,
        ]);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Online',
            'last_name' => 'User',
            'presence_type' => GuestbookEntry::PRESENCE_TYPE_ONLINE,
        ]);

        $component = new GuestbookManager;
        $component->mount($this->event);
        $component->filterPresence = GuestbookEntry::PRESENCE_TYPE_IN_PERSON;

        $response = $component->exportCsv();

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $lines = explode("\n", trim($output));
        expect(count($lines))->toBe(2);
        expect($lines[1])->toContain('In Person');
    });

    test('csv export respects verified filter', function () {
        $this->actingAs($this->admin);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Verified',
            'is_verified' => true,
        ]);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Unverified',
            'is_verified' => false,
        ]);

        $component = new GuestbookManager;
        $component->mount($this->event);
        $component->filterVerified = 'verified';

        $response = $component->exportCsv();

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $lines = explode("\n", trim($output));
        expect(count($lines))->toBe(2);
        expect($lines[1])->toContain('Verified');
        expect($lines[1])->not->toContain('Unverified');
    });

    test('csv export includes verified by name', function () {
        $this->actingAs($this->admin);

        $verifier = User::factory()->create([
            'first_name' => 'Admin',
            'last_name' => 'Verifier',
        ]);

        GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'Test',
            'is_verified' => true,
            'verified_by' => $verifier->id,
        ]);

        $component = new GuestbookManager;
        $component->mount($this->event);

        $response = $component->exportCsv();

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        expect($output)->toContain('Admin Verifier');
    });
});

// =============================================================================
// Audit Logging Tests (1 test)
// =============================================================================

describe('audit logging', function () {
    test('logs guestbook.entry.updated when saveVerification is called', function () {
        $this->actingAs($this->admin);

        $entry = GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
            'is_verified' => false,
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->call('openVerifyModal', $entry->id)
            ->set('editCategory', GuestbookEntry::VISITOR_CATEGORY_MEDIA)
            ->set('editVerified', true)
            ->call('saveVerification');

        $auditLog = AuditLog::where('action', 'guestbook.entry.updated')->first();

        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($this->admin->id);
        expect($auditLog->auditable_type)->toBe(GuestbookEntry::class);
        expect($auditLog->auditable_id)->toBe($entry->id);
        expect($auditLog->old_values)->toMatchArray([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
            'is_verified' => false,
            'verified_by' => null,
            'verified_at' => null,
        ]);
        expect($auditLog->new_values)->toMatchArray([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
            'is_verified' => true,
            'verified_by' => $this->admin->id,
        ]);
    });

    test('logs guestbook.entry.updated when saveVerification unverifies an entry', function () {
        $this->actingAs($this->admin);

        $entry = GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
            'is_verified' => true,
            'verified_by' => $this->admin->id,
            'verified_at' => now(),
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->call('openVerifyModal', $entry->id)
            ->set('editVerified', false)
            ->call('saveVerification');

        $auditLog = AuditLog::where('action', 'guestbook.entry.updated')->first();

        expect($auditLog)->not->toBeNull();
        expect($auditLog->old_values)->toMatchArray([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
            'is_verified' => true,
            'verified_by' => $this->admin->id,
        ]);
        expect($auditLog->new_values)->toMatchArray([
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
            'is_verified' => false,
            'verified_by' => null,
            'verified_at' => null,
        ]);
    });

    test('logs guestbook.entry.deleted when deleteEntry is called', function () {
        $this->actingAs($this->admin);

        $entry = GuestbookEntry::factory()->create([
            'event_configuration_id' => $this->eventConfig->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'callsign' => 'W1AW',
        ]);

        Livewire::test(GuestbookManager::class, ['event' => $this->event])
            ->call('openDeleteModal', $entry->id)
            ->call('deleteEntry');

        $auditLog = AuditLog::where('action', 'guestbook.entry.deleted')->first();

        expect($auditLog)->not->toBeNull();
        expect($auditLog->user_id)->toBe($this->admin->id);
        expect($auditLog->auditable_type)->toBe(GuestbookEntry::class);
        expect($auditLog->auditable_id)->toBe($entry->id);
        expect($auditLog->old_values)->toMatchArray([
            'name' => 'John Doe',
            'callsign' => 'W1AW',
        ]);
        expect($auditLog->new_values)->toBeNull();
    });
});
