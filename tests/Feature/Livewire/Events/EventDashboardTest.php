<?php

use App\Livewire\Events\EventDashboard;
use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventBonus;
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
    Permission::create(['name' => 'create-events']);

    $role = Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    $role->givePermissionTo(['view-events', 'edit-events', 'delete-events', 'create-events']);
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
    // Expect: event + eager loads + computed properties (qsoBreakdown, participants, scoring)
    expect(count($queries))->toBeLessThan(60);
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
        ->assertSee('Elected Official')
        ->assertSee('Media Publicity')
        ->assertSee('200 pts'); // elected official + media = 200
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
    expect($stats['elected_official'])->toBeTrue();
    expect($stats['bonus_points'])->toBe(100); // Only elected official = 100 pts
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
    expect($stats['elected_official'])->toBeFalse();
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

test('recentContacts returns last 25 contacts in reverse chronological order', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $mode = Mode::factory()->cw()->create();
    $band = Band::factory()->create(['name' => '20m']);

    // Create 30 contacts with staggered times
    for ($i = 0; $i < 30; $i++) {
        Contact::factory()->create([
            'event_configuration_id' => $config->id,
            'callsign' => 'K'.str_pad($i, 4, '0', STR_PAD_LEFT),
            'qso_time' => now()->subMinutes(30 - $i),
            'mode_id' => $mode->id,
            'band_id' => $band->id,
        ]);
    }

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);
    $contacts = $component->get('recentContacts');

    expect($contacts)->toHaveCount(25);
    // Most recent first
    expect($contacts->first()->callsign)->toBe('K0029');
    // Oldest of the 25 (skips the first 5)
    expect($contacts->last()->callsign)->toBe('K0005');
});

test('recentContacts tab displays contact data', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $mode = Mode::factory()->phone()->create();
    $band = Band::factory()->create(['name' => '40m']);

    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'callsign' => 'N3XYZ',
        'qso_time' => now()->subMinutes(5),
        'mode_id' => $mode->id,
        'band_id' => $band->id,
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->set('activeTab', 'contacts')
        ->assertSee('N3XYZ')
        ->assertSee('40m')
        ->assertSee('Phone');
});

test('recentContacts tab shows GOTA operator call sign instead of logger for GOTA contacts', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $coach = User::factory()->create(['call_sign' => 'W1COACH']);
    $newOp = User::factory()->create(['call_sign' => 'W1NEWOP']);

    Contact::factory()->gota()->create([
        'event_configuration_id' => $config->id,
        'callsign' => 'KD9ABC',
        'logger_user_id' => $coach->id,
        'gota_operator_user_id' => $newOp->id,
        'gota_operator_first_name' => $newOp->first_name,
        'gota_operator_last_name' => $newOp->last_name,
        'gota_operator_callsign' => $newOp->call_sign,
    ]);

    // The contacts tab should display the GOTA new op's call sign in the Operator column.
    // The coach appears in the Participants section (as logger), so we verify the GOTA op
    // is present and that the recentContacts data has the correct relationship loaded.
    $component = Livewire::test(EventDashboard::class, ['event' => $event])
        ->set('activeTab', 'contacts')
        ->assertSee('W1NEWOP');

    $contacts = $component->get('recentContacts');
    $gotaContact = $contacts->first();

    expect($gotaContact->is_gota_contact)->toBeTrue();
    expect($gotaContact->gotaOperator->call_sign)->toBe('W1NEWOP');
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

test('bandModeGrid returns contact counts grouped by band and mode', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $cwMode = Mode::factory()->cw()->create();
    $phoneMode = Mode::factory()->phone()->create();
    $band20m = Band::factory()->create(['name' => '20m', 'allowed_fd' => true, 'sort_order' => 4]);
    $band40m = Band::factory()->create(['name' => '40m', 'allowed_fd' => true, 'sort_order' => 3]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band20m->id,
        'mode_id' => $cwMode->id,
        'is_duplicate' => false,
        'points' => 2,
    ]);
    Contact::factory()->count(2)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band40m->id,
        'mode_id' => $phoneMode->id,
        'is_duplicate' => false,
        'points' => 1,
    ]);
    // Duplicate should be excluded
    Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band20m->id,
        'mode_id' => $cwMode->id,
        'is_duplicate' => true,
        'points' => 0,
    ]);

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);
    $grid = $component->get('bandModeGrid');

    expect($grid)->not->toBeEmpty();

    // Find CW row
    $cwRow = collect($grid)->first(fn ($row) => $row['mode']->id === $cwMode->id);
    expect($cwRow['cells'][$band20m->id])->toBe(3);
    expect($cwRow['total_count'])->toBe(3);
    expect($cwRow['total_points'])->toBe(6); // 3 × 2 pts
});

test('bonusList returns all bonus types with claimed status', function () {
    $this->actingAs($this->user);

    $eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day', 'is_active' => true],
    );
    $event = Event::factory()->create(['event_type_id' => $eventType->id]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $bonusType1 = BonusType::factory()->create([
        'event_type_id' => $eventType->id,
        'name' => 'Emergency Power',
        'base_points' => 100,
        'is_active' => true,
    ]);
    $bonusType2 = BonusType::factory()->create([
        'event_type_id' => $eventType->id,
        'name' => 'Media Publicity',
        'base_points' => 100,
        'is_active' => true,
    ]);

    // Claim and verify one bonus
    EventBonus::factory()->create([
        'event_configuration_id' => $config->id,
        'bonus_type_id' => $bonusType1->id,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);
    $list = $component->get('bonusList');

    expect($list)->toHaveCount(2);

    $emergencyPower = collect($list)->first(fn ($b) => $b['type']->id === $bonusType1->id);
    expect($emergencyPower['status'])->toBe('verified');
    expect($emergencyPower['points'])->toBe(100);

    $media = collect($list)->first(fn ($b) => $b['type']->id === $bonusType2->id);
    expect($media['status'])->toBe('unclaimed');
});

test('scoring tab displays band/mode grid and bonus checklist', function () {
    $this->actingAs($this->user);

    $eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day', 'is_active' => true],
    );
    $event = Event::factory()->create(['event_type_id' => $eventType->id]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'max_power_watts' => 100,
    ]);

    $cwMode = Mode::factory()->cw()->create();
    $band = Band::factory()->create(['name' => '20m', 'allowed_fd' => true, 'sort_order' => 4]);

    Contact::factory()->count(3)->create([
        'event_configuration_id' => $config->id,
        'band_id' => $band->id,
        'mode_id' => $cwMode->id,
        'is_duplicate' => false,
        'points' => 2,
    ]);

    $bonusType = BonusType::factory()->create([
        'event_type_id' => $eventType->id,
        'name' => 'Emergency Power',
        'base_points' => 100,
        'is_active' => true,
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->set('activeTab', 'scoring')
        ->assertSee('QSO Points')
        ->assertSee('20m')
        ->assertSee('CW')
        ->assertSee('Bonus Points')
        ->assertSee('Emergency Power')
        ->assertSee('Final Score');
});

test('scoringTotals returns correct QSO and bonus scores', function () {
    $this->actingAs($this->user);

    $event = Event::factory()->create();
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
        'max_power_watts' => 100,
    ]);

    $cwMode = Mode::factory()->cw()->create();
    $band = Band::factory()->create(['allowed_fd' => true]);

    Contact::factory()->count(5)->create([
        'event_configuration_id' => $config->id,
        'mode_id' => $cwMode->id,
        'band_id' => $band->id,
        'is_duplicate' => false,
        'points' => 2,
    ]);

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);
    $totals = $component->get('scoringTotals');

    expect($totals['qso_base_points'])->toBe(10); // 5 × 2
    expect($totals['power_multiplier'])->toBe(2);  // 100W = 2×
    expect($totals['qso_score'])->toBe(20);        // 10 × 2
    expect($totals['final_score'])->toBe(20);      // 20 + 0 bonus
});

test('equipment tab shows dashboard link for active events', function () {
    Permission::create(['name' => 'manage-event-equipment']);
    $this->user->givePermissionTo('manage-event-equipment');
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subHours(2),
        'end_time' => now()->addHours(22),
    ]);
    EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->set('activeTab', 'equipment')
        ->assertSee('Go to Equipment Dashboard');
});

test('equipment tab shows historical equipment list for completed events', function () {
    Permission::create(['name' => 'manage-event-equipment']);
    $this->user->givePermissionTo('manage-event-equipment');
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);
    $config = EventConfiguration::factory()->create([
        'event_id' => $event->id,
        'callsign' => 'W1AW',
    ]);

    $equipment = Equipment::factory()->create([
        'make' => 'Icom',
        'model' => 'IC-7300',
        'type' => 'radio',
    ]);
    EquipmentEvent::create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'returned',
        'committed_at' => now()->subDays(31),
        'status_changed_at' => now()->subDays(29),
    ]);

    Livewire::test(EventDashboard::class, ['event' => $event])
        ->set('activeTab', 'equipment')
        ->assertSee('IC-7300')
        ->assertSee('Icom')
        ->assertDontSee('Go to Equipment Dashboard');
});

test('equipmentCommitments returns commitments for the event', function () {
    Permission::create(['name' => 'manage-event-equipment']);
    $this->user->givePermissionTo('manage-event-equipment');
    $this->actingAs($this->user);

    $event = Event::factory()->create([
        'start_time' => now()->subDays(30),
        'end_time' => now()->subDays(29),
    ]);

    $equipment = Equipment::factory()->create(['type' => 'radio']);
    EquipmentEvent::create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'returned',
        'committed_at' => now()->subDays(31),
        'status_changed_at' => now()->subDays(29),
    ]);

    $component = Livewire::test(EventDashboard::class, ['event' => $event]);
    $commitments = $component->get('equipmentCommitments');

    expect($commitments)->toHaveCount(1);
    expect($commitments->first()->equipment->type)->toBe('radio');
});
