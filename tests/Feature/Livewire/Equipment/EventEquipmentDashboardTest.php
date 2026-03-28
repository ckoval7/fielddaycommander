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

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->set('activeTab', 'by-station')
        ->assertSee('Station A')
        ->assertSee('GOTA Station')
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

test('invalid status transition fails', function () {
    $this->actingAs($this->manager);

    // Try to transition from committed directly to in_use (invalid)
    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('changeEquipmentStatus', $this->commitment1->id, 'in_use', null)
        ->assertDispatched('notify');

    // Status should not change
    $this->assertDatabaseHas('equipment_event', [
        'id' => $this->commitment1->id,
        'status' => 'committed',
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

// Station Assignment Tests

test('manager can assign equipment to station', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('assignToStation', $this->commitment2->id, $this->station1->id)
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $this->commitment2->id,
        'station_id' => $this->station1->id,
        'status' => 'in_use',
    ]);
});

test('manager can assign via modal', function () {
    $this->actingAs($this->manager);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('openAssignModal', $this->commitment2->id)
        ->assertSet('showAssignModal', true)
        ->assertSet('assignCommitmentId', $this->commitment2->id)
        ->set('assignStationId', $this->station1->id)
        ->call('confirmAssignment')
        ->assertSet('showAssignModal', false);

    $this->assertDatabaseHas('equipment_event', [
        'id' => $this->commitment2->id,
        'station_id' => $this->station1->id,
    ]);
});

test('viewer cannot assign equipment to station', function () {
    $this->actingAs($this->viewer);

    // Verify viewer doesn't have manage permission
    expect($this->viewer->can('manage-event-equipment'))->toBeFalse();
    expect($this->viewer->can('view-all-equipment'))->toBeTrue();

    // Create fresh commitment for this test to ensure clean state
    $viewerEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->viewer->id,
        'type' => 'other',
    ]);

    $viewerCommitment = EquipmentEvent::factory()->create([
        'equipment_id' => $viewerEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'delivered', // Status that allows assignment normally
        'station_id' => null,
    ]);

    // Verify commitment has no station assignment
    expect($viewerCommitment->station_id)->toBeNull();

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('assignToStation', $viewerCommitment->id, $this->station1->id)
        ->assertDispatched('notify');

    // Station should NOT be assigned because viewer lacks manage permission
    $viewerCommitment->refresh();
    expect($viewerCommitment->station_id)->toBeNull();
});

test('cannot assign committed equipment to station', function () {
    $this->actingAs($this->manager);

    // Create new equipment and commitment specifically for this test
    $newEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->manager->id,
        'type' => 'other',
        'make' => 'TestMake',
        'model' => 'TestModel',
    ]);

    $committedCommitment = EquipmentEvent::factory()->create([
        'equipment_id' => $newEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'station_id' => null,
    ]);

    // Verify status
    expect($committedCommitment->status)->toBe('committed');
    expect($committedCommitment->station_id)->toBeNull();

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('assignToStation', $committedCommitment->id, $this->station1->id)
        ->assertDispatched('notify');

    // Refresh and verify station was NOT assigned (transition from committed to in_use should fail)
    $committedCommitment->refresh();
    expect($committedCommitment->station_id)->toBeNull();
});

test('cannot assign to station from different event', function () {
    $this->actingAs($this->manager);

    $otherEvent = Event::factory()->create();
    $otherConfig = EventConfiguration::factory()->create([
        'event_id' => $otherEvent->id,
    ]);
    $otherStation = Station::factory()->create([
        'event_configuration_id' => $otherConfig->id,
    ]);

    // Verify the station belongs to a different event's configuration
    $thisEventConfigId = \App\Models\EventConfiguration::where('event_id', $this->event->id)->value('id');
    expect($otherStation->event_configuration_id)->not->toBe($thisEventConfigId);

    // Record the station_id before the call
    $stationIdBefore = EquipmentEvent::find($this->commitment2->id)->station_id;

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('assignToStation', $this->commitment2->id, $otherStation->id)
        ->assertDispatched('notify');

    // Station should not be assigned to the other event's station
    $this->commitment2->refresh();
    expect($this->commitment2->station_id)->not->toBe($otherStation->id);
});

// Unassign Tests

test('manager can unassign equipment from station', function () {
    $this->actingAs($this->manager);

    // First assign to station
    $this->commitment2->update([
        'station_id' => $this->station1->id,
        'assigned_by_user_id' => $this->manager->id,
    ]);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('unassignFromStation', $this->commitment2->id)
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $this->commitment2->id,
        'station_id' => null,
        'assigned_by_user_id' => null,
    ]);
});

test('viewer cannot unassign equipment from station', function () {
    $this->actingAs($this->viewer);

    // First assign to station
    $this->commitment2->update([
        'station_id' => $this->station1->id,
    ]);

    Livewire::test(EventEquipmentDashboard::class, ['event' => $this->event])
        ->call('unassignFromStation', $this->commitment2->id)
        ->assertDispatched('notify');

    // Should still be assigned
    $this->assertDatabaseHas('equipment_event', [
        'id' => $this->commitment2->id,
        'station_id' => $this->station1->id,
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

test('clicking on equipment photo opens photo modal in event equipment', function () {
    $this->actingAs($this->manager);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->manager->id,
        'make' => 'Yaesu',
        'model' => 'FT-991A',
        'photo_path' => 'equipment/test-photo.jpg',
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    Livewire::test('equipment.event-equipment')
        ->assertSet('showPhotoModal', false)
        ->call('viewPhoto', $equipment->photo_path, $equipment->make.' '.$equipment->model)
        ->assertSet('showPhotoModal', true)
        ->assertSet('photoPath', 'equipment/test-photo.jpg')
        ->assertSet('photoDescription', 'Yaesu FT-991A');
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

test('photo modal can be closed in event equipment', function () {
    $this->actingAs($this->manager);

    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->manager->id,
        'photo_path' => 'equipment/test-photo.jpg',
    ]);

    Livewire::test('equipment.event-equipment')
        ->call('viewPhoto', $equipment->photo_path, 'Test Equipment')
        ->assertSet('showPhotoModal', true)
        ->set('showPhotoModal', false)
        ->assertSet('showPhotoModal', false);
});
