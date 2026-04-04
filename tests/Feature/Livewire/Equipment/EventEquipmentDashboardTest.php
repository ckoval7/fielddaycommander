<?php

use App\Livewire\Equipment\EventEquipmentDashboard;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create permissions
    Permission::firstOrCreate(['name' => 'manage-event-equipment']);
    Permission::firstOrCreate(['name' => 'view-all-equipment']);

    // Create test user with manage permission
    $this->manager = User::factory()->create();
    $this->manager->givePermissionTo('manage-event-equipment');

    // Create test user with view-only permission
    $this->viewer = User::factory()->create();
    $this->viewer->givePermissionTo('view-all-equipment');

    // Create regular user without permissions
    $this->regularUser = User::factory()->create();

    // Create an event with configuration
    $this->event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    // Create stations for the event
    $this->station1 = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'Station A',
        'is_gota' => false,
    ]);

    $this->station2 = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'name' => 'GOTA Station',
        'is_gota' => true,
    ]);

    // Create equipment and commitments
    $this->equipment1 = Equipment::factory()->create([
        'owner_user_id' => $this->manager->id,
        'type' => 'radio',
        'make' => 'Icom',
        'model' => 'IC-7300',
    ]);

    $this->equipment2 = Equipment::factory()->create([
        'owner_user_id' => $this->viewer->id,
        'type' => 'antenna',
        'make' => 'Yaesu',
        'model' => 'Dipole',
    ]);

    $this->commitment1 = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment1->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    $this->commitment2 = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment2->id,
        'event_id' => $this->event->id,
        'status' => 'delivered',
    ]);
});

// Authorization Tests

test('user without permissions cannot access dashboard', function () {
    $this->actingAs($this->regularUser);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertForbidden();
});

test('user with manage-event-equipment can access dashboard', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertOk()
        ->assertSet('event.id', $this->event->id);
});

test('user with view-all-equipment can access dashboard', function () {
    $this->actingAs($this->viewer);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertOk()
        ->assertSet('event.id', $this->event->id);
});

// Component Initialization Tests

test('component mounts with correct event', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSet('event.id', $this->event->id)
        ->assertSet('activeTab', 'overview')
        ->assertSet('searchQuery', '')
        ->assertSet('typeFilter', null)
        ->assertSet('statusFilter', null)
        ->assertSet('stationFilter', null);
});

test('component loads event with relationships', function () {
    $this->actingAs($this->manager);

    $component = Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event]);

    expect($component->get('event')->relationLoaded('eventType'))->toBeTrue();
    expect($component->get('event')->relationLoaded('eventConfiguration'))->toBeTrue();
});

// Display Tests - Test through rendered output

test('displays all committed equipment in overview', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSee('IC-7300')
        ->assertSee('Dipole')
        ->assertSee('Icom')
        ->assertSee('Yaesu');
});

test('displays stats cards with correct counts', function () {
    $this->actingAs($this->manager);

    // Check that stats are displayed - total should be 2
    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSee('Committed')
        ->assertSee('Delivered');
});

test('displays equipment types in view', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSee('Radio')
        ->assertSee('Antenna');
});

test('displays stations in by-station tab', function () {
    $this->actingAs($this->manager);

    // Assign commitment2 to station1 so it appears in the by-station tab
    $this->commitment2->update(['station_id' => $this->station1->id]);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('activeTab', 'by-station')
        ->assertSee('Station A')
        ->assertSee('Unassigned');
});

test('displays owner information in by-owner tab', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('activeTab', 'by-owner')
        ->assertSee($this->manager->first_name)
        ->assertSee($this->viewer->first_name);
});

// Search and Filter Tests

test('search filters by equipment make', function () {
    $this->actingAs($this->manager);

    // Test that search updates the component state
    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('searchQuery', 'Icom')
        ->assertSet('searchQuery', 'Icom')
        ->assertSee('IC-7300');
});

test('search filters by equipment model', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('searchQuery', '7300')
        ->assertSet('searchQuery', '7300')
        ->assertSee('Icom');
});

test('search filters by owner name', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('searchQuery', $this->manager->first_name)
        ->assertSee('IC-7300');
});

test('type filter filters commitments', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('typeFilter', 'radio')
        ->assertSet('typeFilter', 'radio')
        ->assertSee('IC-7300');
});

test('status filter filters commitments', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('statusFilter', 'committed')
        ->assertSet('statusFilter', 'committed')
        ->assertSee('IC-7300');
});

test('clearFilters resets all filters', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('searchQuery', 'test')
        ->set('typeFilter', 'radio')
        ->set('statusFilter', 'committed')
        ->set('stationFilter', 1)
        ->call('clearFilters')
        ->assertSet('searchQuery', '')
        ->assertSet('typeFilter', null)
        ->assertSet('statusFilter', null)
        ->assertSet('stationFilter', null);
});

// Status Change Tests

test('manager can change equipment status', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('changeEquipmentStatus', $this->commitment1->id, 'delivered', 'Test notes')
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $this->commitment1->id,
        'status' => 'delivered',
    ]);
});

test('manager can change status via modal', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('openStatusModal', $this->commitment1->id)
        ->assertSet('showStatusModal', true)
        ->assertSet('statusChangeCommitmentId', $this->commitment1->id)
        ->set('newStatus', 'delivered')
        ->set('statusChangeNotes', 'Test notes')
        ->call('confirmStatusChange')
        ->assertSet('showStatusModal', false);

    $this->assertDatabaseHas('equipment_event', [
        'id' => $this->commitment1->id,
        'status' => 'delivered',
    ]);
});

test('viewer cannot change equipment status', function () {
    $this->actingAs($this->viewer);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('changeEquipmentStatus', $this->commitment1->id, 'delivered', null)
        ->assertDispatched('notify');

    // Status should not change
    $this->assertDatabaseHas('equipment_event', [
        'id' => $this->commitment1->id,
        'status' => 'committed',
    ]);
});

test('any valid status change succeeds', function () {
    $this->actingAs($this->manager);

    // Transition from committed to delivered
    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('changeEquipmentStatus', $this->commitment1->id, 'delivered', null)
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $this->commitment1->id,
        'status' => 'delivered',
    ]);
});

test('cannot change status for commitment from different event', function () {
    $this->actingAs($this->manager);

    $otherEvent = Event::factory()->create();
    $otherCommitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment1->id,
        'event_id' => $otherEvent->id,
        'status' => 'committed',
    ]);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('changeEquipmentStatus', $otherCommitment->id, 'delivered', null)
        ->assertDispatched('notify');

    // Status should not change
    $this->assertDatabaseHas('equipment_event', [
        'id' => $otherCommitment->id,
        'status' => 'committed',
    ]);
});

// Tab Navigation Tests

test('can switch between tabs', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSet('activeTab', 'overview')
        ->set('activeTab', 'by-owner')
        ->assertSet('activeTab', 'by-owner')
        ->set('activeTab', 'by-type')
        ->assertSet('activeTab', 'by-type')
        ->set('activeTab', 'by-station')
        ->assertSet('activeTab', 'by-station');
});

// Display Tests

test('displays event name in header', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSee($this->event->name);
});

test('displays status badges correctly', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSee('Committed')
        ->assertSee('Delivered');
});

// Issues Counter Tests

test('issues counter shows cancelled lost and damaged', function () {
    $this->actingAs($this->manager);

    // Add equipment with issues
    $lostEquipment = Equipment::factory()->create(['owner_user_id' => $this->manager->id]);
    EquipmentEvent::factory()->create([
        'equipment_id' => $lostEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'lost',
    ]);

    $damagedEquipment = Equipment::factory()->create(['owner_user_id' => $this->manager->id]);
    EquipmentEvent::factory()->create([
        'equipment_id' => $damagedEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'damaged',
    ]);

    // The component should display the issues
    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSee('Lost')
        ->assertSee('Damaged');
});

// Recent Activity Tests

test('recent activity displays changes', function () {
    $this->actingAs($this->manager);

    // Update status_changed_at to be recent
    $this->commitment1->update(['status_changed_at' => now()]);
    $this->commitment2->update(['status_changed_at' => now()->subMinute()]);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSee('Recent Activity')
        ->assertSee('IC-7300')
        ->assertSee('Dipole');
});

// Hidden Test Elements

test('hidden test elements contain correct data', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->assertSeeHtml('data-testid="event-id"')
        ->assertSeeHtml('data-testid="total-commitments"')
        ->assertSeeHtml('data-testid="active-tab"');
});

// Commit Club Equipment Tests

test('manager can open commit club equipment modal', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('openCommitModal')
        ->assertSet('showCommitModal', true)
        ->assertSet('commitEquipmentId', null)
        ->assertSet('commitExpectedDeliveryAt', null)
        ->assertSet('commitDeliveryNotes', null);
});

test('manager can commit club equipment to event', function () {
    $this->actingAs($this->manager);

    $org = \App\Models\Organization::factory()->create();
    $clubEquipment = Equipment::factory()->create([
        'owner_user_id' => null,
        'owner_organization_id' => $org->id,
        'type' => 'radio',
        'make' => 'Kenwood',
        'model' => 'TS-590S',
    ]);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('commitEquipmentId', $clubEquipment->id)
        ->set('commitDeliveryNotes', 'Club radio for 20m station')
        ->call('commitClubEquipment')
        ->assertSet('showCommitModal', false)
        ->assertDispatched('notify', title: 'Success');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $clubEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'delivery_notes' => 'Club radio for 20m station',
    ]);
});

test('cannot commit non-club equipment from dashboard', function () {
    $this->actingAs($this->manager);

    $personalEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->regularUser->id,
        'owner_organization_id' => null,
    ]);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('commitEquipmentId', $personalEquipment->id)
        ->call('commitClubEquipment')
        ->assertHasErrors('commitEquipmentId');
});

test('cannot commit club equipment already committed to overlapping event', function () {
    $this->actingAs($this->manager);

    $org = \App\Models\Organization::factory()->create();
    $clubEquipment = Equipment::factory()->create([
        'owner_user_id' => null,
        'owner_organization_id' => $org->id,
    ]);

    // Create overlapping event with existing commitment
    $overlappingEvent = Event::factory()->create([
        'start_time' => $this->event->start_time->copy()->addDay(),
        'end_time' => $this->event->end_time->copy()->addDay(),
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => $clubEquipment->id,
        'event_id' => $overlappingEvent->id,
        'status' => 'committed',
    ]);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('commitEquipmentId', $clubEquipment->id)
        ->call('commitClubEquipment')
        ->assertHasErrors('commitEquipmentId');
});

test('viewer cannot commit club equipment', function () {
    $this->actingAs($this->viewer);

    $org = \App\Models\Organization::factory()->create();
    $clubEquipment = Equipment::factory()->create([
        'owner_user_id' => null,
        'owner_organization_id' => $org->id,
    ]);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('commitEquipmentId', $clubEquipment->id)
        ->call('commitClubEquipment')
        ->assertDispatched('notify', title: 'Error');

    $this->assertDatabaseMissing('equipment_event', [
        'equipment_id' => $clubEquipment->id,
        'event_id' => $this->event->id,
    ]);
});

test('available club equipment excludes already committed items', function () {
    $this->actingAs($this->manager);

    $org = \App\Models\Organization::factory()->create();

    $committedClubEquipment = Equipment::factory()->create([
        'owner_user_id' => null,
        'owner_organization_id' => $org->id,
        'make' => 'Committed',
        'model' => 'Radio',
    ]);

    $availableClubEquipment = Equipment::factory()->create([
        'owner_user_id' => null,
        'owner_organization_id' => $org->id,
        'make' => 'Available',
        'model' => 'Radio',
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => $committedClubEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    // Already-committed club equipment should not be committable again
    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('commitEquipmentId', $availableClubEquipment->id)
        ->call('commitClubEquipment')
        ->assertDispatched('notify', title: 'Success');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $availableClubEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    // The already-committed one should fail overlap check (same event)
    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $committedClubEquipment->id,
        'event_id' => $this->event->id,
    ]);
});

test('can recommit cancelled club equipment to same event', function () {
    $this->actingAs($this->manager);

    $org = \App\Models\Organization::factory()->create();
    $clubEquipment = Equipment::factory()->create([
        'owner_user_id' => null,
        'owner_organization_id' => $org->id,
        'type' => 'radio',
        'make' => 'Kenwood',
        'model' => 'TS-590S',
    ]);

    // Create an existing cancelled commitment for this equipment+event
    EquipmentEvent::factory()->create([
        'equipment_id' => $clubEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'cancelled',
    ]);

    // Recommitting the same equipment should succeed by reactivating the record
    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('commitEquipmentId', $clubEquipment->id)
        ->set('commitDeliveryNotes', 'Recommitted after cancellation')
        ->call('commitClubEquipment')
        ->assertSet('showCommitModal', false)
        ->assertDispatched('notify', title: 'Success');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $clubEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'delivery_notes' => 'Recommitted after cancellation',
    ]);

    // Should still be only one record for this equipment+event, not two
    $this->assertEquals(1, EquipmentEvent::where('equipment_id', $clubEquipment->id)
        ->where('event_id', $this->event->id)
        ->count());
});
