<?php

use App\Livewire\Events\EventDashboard;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\GuestbookEntry;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create();

    // Create permissions
    Permission::create(['name' => 'view-events']);
    Permission::create(['name' => 'edit-events']);
    Permission::create(['name' => 'delete-events']);
    Permission::create(['name' => 'activate-events']);
    Permission::create(['name' => 'create-events']);

    $role = Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-events', 'edit-events', 'delete-events', 'activate-events', 'create-events']);
    $this->user->assignRole($role);
});

test('event dashboard requires view-events permission', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    $event = Event::factory()->create();

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertForbidden();
});

test('event dashboard displays event details', function () {
    $this->actingAs($this->user);

    $eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);
    $event = Event::factory()->create([
        'name' => 'Field Day 2025',
        'event_type_id' => $eventType->id,
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertSee('Field Day 2025')
        ->assertSee('Field Day');
});

test('event dashboard displays configuration card', function () {
    $this->actingAs($this->user);

    $eventType = EventType::create([
        'code' => 'FD',
        'name' => 'Field Day',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);
    $section = Section::create([
        'code' => 'ORG',
        'name' => 'Orange',
        'region' => 'W6',
        'country' => 'US',
        'is_active' => true,
    ]);
    $operatingClass = OperatingClass::create([
        'event_type_id' => $eventType->id,
        'code' => '3A',
        'name' => 'Class 3A',
        'description' => '3 transmitters',
    ]);
    $event = Event::factory()->create([
        'name' => 'Test Event',
        'event_type_id' => $eventType->id,
    ]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'club_name' => 'ARRL HQ',
        'section_id' => $section->id,
        'operating_class_id' => $operatingClass->id,
        'transmitter_count' => 3,
        'max_power_watts' => 100,
        'uses_battery' => true,
        'uses_solar' => true,
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertSee('W1AW')
        ->assertSee('ARRL HQ')
        ->assertSee('ORG')
        ->assertSee('3A')
        ->assertSee('Battery')
        ->assertSee('Solar');
});

test('event dashboard shows scoring summary', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create(['name' => 'Test Event']);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertSee('Scoring Summary')
        // Should show 0s for now since contacts aren't implemented
        ->assertSee('0'); // Should appear in contacts/points display
});

test('event dashboard shows correct status badge', function () {
    $this->actingAs($this->user);

    // Test upcoming event
    $upcomingEvent = Event::factory()->create([
        'name' => 'Upcoming Event',
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(11),
    ]);

    Livewire::test(EventDashboard::class, ['event' => $upcomingEvent])
        ->assertSee('Upcoming');

    // Test active event (within date range)
    $activeEvent = Event::factory()->create([
        'name' => 'Active Event',
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);

    Livewire::test(EventDashboard::class, ['event' => $activeEvent])
        ->assertSee('Active');

    // Test completed event
    $completedEvent = Event::factory()->create([
        'name' => 'Completed Event',
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);

    Livewire::test(EventDashboard::class, ['event' => $completedEvent])
        ->assertSee('Completed');
});

test('event dashboard eager loads relationships', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create(['event_id' => $event->id]);

    // Enable query logging
    \DB::enableQueryLog();

    Livewire::test(EventDashboard::class, ['event' => $event]);

    $queries = \DB::getQueryLog();

    // Should have minimal queries due to eager loading
    // Expect: main event query with eager loads, setting query for active_event_id
    // With all the relationships and computed properties, expect fewer than 25 queries
    expect(count($queries))->toBeLessThan(25);
});

test('event dashboard displays guestbook stats when guestbook is enabled', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'guestbook_enabled' => true,
    ]);

    // Create some guestbook entries
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $config->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_GENERAL_PUBLIC,
        'is_verified' => false,
    ]);
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $config->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
        'is_verified' => true,
    ]);
    GuestbookEntry::factory()->create([
        'event_configuration_id' => $config->id,
        'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_MEDIA,
        'is_verified' => true,
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertSee('Guestbook Visitors')
        ->assertSee('Total Visitors')
        ->assertSee('3') // Total visitors
        ->assertSee('Verified Bonus Eligible')
        ->assertSee('2 / 10') // 2 verified bonus-eligible
        ->assertSee('PR Bonus')
        ->assertSee('200 pts'); // 2 × 100 = 200 points
});

test('event dashboard hides guestbook stats when guestbook is disabled', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'guestbook_enabled' => false,
    ]);

    // Refresh event to ensure relationships are loaded
    $event = $event->fresh();

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertDontSee('Guestbook Visitors');
});

test('event dashboard calculates correct bonus points with max cap', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'guestbook_enabled' => true,
    ]);

    // Create 15 verified bonus-eligible entries (more than the 10 cap)
    for ($i = 0; $i < 15; $i++) {
        GuestbookEntry::factory()->create([
            'event_configuration_id' => $config->id,
            'visitor_category' => GuestbookEntry::VISITOR_CATEGORY_ELECTED_OFFICIAL,
            'is_verified' => true,
        ]);
    }

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);

    $stats = $component->get('guestbookStats');
    expect($stats['total'])->toBe(15);
    expect($stats['verified_bonus_eligible'])->toBe(15);
    expect($stats['bonus_points'])->toBe(1000); // Capped at 10 × 100 = 1000
});

test('event dashboard guestbook stats returns zeros when guestbook is disabled', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'guestbook_enabled' => false,
    ]);

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);

    $stats = $component->get('guestbookStats');
    expect($stats['total'])->toBe(0);
    expect($stats['verified_bonus_eligible'])->toBe(0);
    expect($stats['bonus_points'])->toBe(0);
});

test('event dashboard shows manage guestbook button with permission', function () {
    Permission::create(['name' => 'manage-guestbook']);
    $this->user->givePermissionTo('manage-guestbook');

    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'guestbook_enabled' => true,
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertSee('Manage Guestbook');
});

test('event dashboard hides manage guestbook button without permission', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'guestbook_enabled' => true,
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->assertStatus(200)
        ->assertDontSee('Manage Guestbook');
});

test('qsoBreakdown returns real contact counts by mode category', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $cwMode = Mode::factory()->cw()->create();
    $phoneMode = Mode::factory()->phone()->create();
    $digitalMode = Mode::factory()->digital()->create();

    // Create contacts: 3 CW, 2 Phone, 1 Digital, 1 duplicate (should be excluded from category counts but included in total)
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'mode_id' => $cwMode->id,
        'is_duplicate' => false,
    ]);
    Contact::factory()->count(2)->create([
        'event_configuration_id' => $config->id,
        'mode_id' => $phoneMode->id,
        'is_duplicate' => false,
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'mode_id' => $digitalMode->id,
        'is_duplicate' => false,
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'mode_id' => $cwMode->id,
        'is_duplicate' => true,
        'points' => 0,
    ]);

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);
    $breakdown = $component->get('qsoBreakdown');

    expect($breakdown['total_contacts'])->toBe(7);
    expect($breakdown['cw_contacts'])->toBe(3);
    expect($breakdown['phone_contacts'])->toBe(2);
    expect($breakdown['digital_contacts'])->toBe(1);
});

test('participants returns users who logged contacts with counts', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $operator1 = User::factory()->create(['call_sign' => 'W1AAA']);
    $operator2 = User::factory()->create(['call_sign' => 'W1BBB']);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $config->id,
        'logger_user_id' => $operator1->id,
    ]);
    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'logger_user_id' => $operator2->id,
    ]);

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);
    $participants = $component->get('participants');

    expect($participants)->toHaveCount(2);
    // Sorted by contact_count descending
    expect($participants[0]['name'])->toBe('W1AAA');
    expect($participants[0]['contact_count'])->toBe(5);
    expect($participants[1]['name'])->toBe('W1BBB');
    expect($participants[1]['contact_count'])->toBe(3);
});

test('participants returns empty array when no contacts exist', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);
    $participants = $component->get('participants');

    expect($participants)->toBeEmpty();
});

test('qsoBreakdown returns zeros when no contacts exist', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);
    $breakdown = $component->get('qsoBreakdown');

    expect($breakdown['total_contacts'])->toBe(0);
    expect($breakdown['cw_contacts'])->toBe(0);
    expect($breakdown['phone_contacts'])->toBe(0);
    expect($breakdown['digital_contacts'])->toBe(0);
});
