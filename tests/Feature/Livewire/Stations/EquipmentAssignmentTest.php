<?php

use App\Livewire\Stations\EquipmentAssignment;
use App\Models\Band;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    // Create permissions
    Permission::firstOrCreate(['name' => 'manage-stations']);
    Permission::firstOrCreate(['name' => 'view-all-equipment']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo(['manage-stations', 'view-all-equipment']);
    $this->actingAs($this->user);

    // Create event infrastructure
    $eventType = EventType::create([
        'name' => 'Field Day',
        'code' => 'FD',
        'description' => 'ARRL Field Day',
        'is_active' => true,
    ]);

    $section = Section::create([
        'code' => 'CO',
        'name' => 'Colorado',
        'region' => 'W0',
        'country' => 'US',
        'is_active' => true,
    ]);

    $operatingClass = OperatingClass::create([
        'event_type_id' => $eventType->id,
        'code' => '3A',
        'name' => 'Class 3A',
        'allows_gota' => true,
        'max_power_watts' => 150,
        'requires_emergency_power' => false,
    ]);

    $this->event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        'is_active' => true,
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'section_id' => $section->id,
        'operating_class_id' => $operatingClass->id,
    ]);

    // Create primary radio
    $this->radio = Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Kenwood',
        'model' => 'TS-590SG',
        'power_output_watts' => 100,
        'owner_user_id' => $this->user->id,
    ]);

    // Create station
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Phone Station 1',
    ]);
});

test('component can render with station', function () {
    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->assertStatus(200)
        ->assertSee('Equipment Assignment')
        ->assertSee('Phone Station 1');
});

test('shows primary radio in assigned section', function () {
    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->assertSee('Primary Radio')
        ->assertSee('Kenwood')
        ->assertSee('TS-590SG');
});

test('shows empty state when no additional equipment assigned', function () {
    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->assertSee('No additional equipment assigned');
});

test('can assign equipment from committed tab', function () {
    // Create antenna committed to event but not assigned to station
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'SteppIR',
        'model' => 'BigIR',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => null,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $antenna->id, false)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => $this->station->id,
    ]);
});

test('can assign equipment from catalog with commit', function () {
    // Create antenna not committed to event
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Buddipole',
        'model' => 'Deluxe',
        'owner_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $antenna->id, true)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => $this->station->id,
        'status' => 'committed',
    ]);
});

test('cannot assign radio as additional equipment', function () {
    $anotherRadio = Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Icom',
        'model' => 'IC-7300',
        'owner_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $anotherRadio->id, true)
        ->assertDispatched('toast');

    // Verify no equipment_event was created for the radio
    $this->assertDatabaseMissing('equipment_event', [
        'equipment_id' => $anotherRadio->id,
        'event_id' => $this->event->id,
    ]);
});

test('can unassign equipment', function () {
    // Create and assign amplifier
    $amplifier = Equipment::factory()->create([
        'type' => 'amplifier',
        'make' => 'Elecraft',
        'model' => 'KPA1500',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $amplifier->id,
        'event_id' => $this->event->id,
        'station_id' => $this->station->id,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
        'assigned_by_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('unassignEquipment', $amplifier->id)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $amplifier->id,
        'event_id' => $this->event->id,
        'station_id' => null, // Unassigned
    ]);
});

test('requestUnassign directly unassigns equipment', function () {
    $computer = Equipment::factory()->create([
        'type' => 'computer',
        'make' => 'Dell',
        'model' => 'Latitude',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $computer->id,
        'event_id' => $this->event->id,
        'station_id' => $this->station->id,
        'status' => 'delivered',
        'committed_at' => now(),
        'status_changed_at' => now(),
        'assigned_by_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('requestUnassign', $computer->id)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $computer->id,
        'event_id' => $this->event->id,
        'station_id' => null,
    ]);
});

test('detects conflict when equipment assigned to another station', function () {
    // Create another station
    $anotherRadio = Equipment::factory()->create(['type' => 'radio', 'owner_user_id' => $this->user->id]);
    $anotherStation = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $anotherRadio->id,
        'name' => 'CW Station',
    ]);

    // Create antenna assigned to another station
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Hexbeam',
        'model' => 'Classic',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => $anotherStation->id,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
        'assigned_by_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $antenna->id, false)
        ->assertSet('showConflictModal', true)
        ->assertSet('conflictEquipmentId', $antenna->id);
});

test('can resolve conflict and reassign equipment', function () {
    // Create another station
    $anotherRadio = Equipment::factory()->create(['type' => 'radio', 'owner_user_id' => $this->user->id]);
    $anotherStation = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $anotherRadio->id,
        'name' => 'Digital Station',
    ]);

    // Create antenna assigned to another station
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Comet',
        'model' => 'GP-9',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => $anotherStation->id,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
        'assigned_by_user_id' => $this->user->id,
    ]);

    // First call triggers conflict
    $component = Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $antenna->id, false)
        ->assertSet('showConflictModal', true);

    // Confirm reassignment
    $component->call('confirmReassignment')
        ->assertSet('showConflictModal', false)
        ->assertDispatched('toast')
        ->assertDispatched('equipment-reassigned');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => $this->station->id, // Reassigned
    ]);
});

test('can search available equipment', function () {
    Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Cushcraft',
        'model' => 'A3S',
        'owner_user_id' => $this->user->id,
    ]);

    Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Mosley',
        'model' => 'PRO-67',
        'owner_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->set('searchQuery', 'Cushcraft')
        ->assertSee('Cushcraft')
        ->assertDontSee('Mosley');
});

test('can filter by equipment type', function () {
    Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Test Antenna',
        'model' => 'ANT-1',
        'owner_user_id' => $this->user->id,
    ]);

    Equipment::factory()->create([
        'type' => 'amplifier',
        'make' => 'Test Amp',
        'model' => 'AMP-1',
        'owner_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->set('typeFilter', 'antenna')
        ->assertSee('Test Antenna')
        ->assertDontSee('Test Amp');
});

test('can filter by owner', function () {
    $otherUser = User::factory()->create(['call_sign' => 'W5XYZ']);

    Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'My Antenna',
        'model' => 'ANT-MINE',
        'owner_user_id' => $this->user->id,
    ]);

    Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Other Antenna',
        'model' => 'ANT-OTHER',
        'owner_user_id' => $otherUser->id,
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->set('ownerFilter', 'my')
        ->assertSee('My Antenna')
        ->assertDontSee('Other Antenna');
});

test('can clear all filters', function () {
    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->set('searchQuery', 'test')
        ->set('typeFilter', 'antenna')
        ->set('ownerFilter', 'my')
        ->call('clearFilters')
        ->assertSet('searchQuery', '')
        ->assertSet('typeFilter', null)
        ->assertSet('ownerFilter', 'all');
});

test('can view equipment details', function () {
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Diamond',
        'model' => 'X50A',
        'description' => 'Dual band vertical',
        'owner_user_id' => $this->user->id,
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('showDetails', $antenna->id)
        ->assertSet('showDetailsModal', true)
        ->assertSet('detailsEquipmentId', $antenna->id);
});

test('displays assigned equipment count and value', function () {
    // Create and assign equipment with value
    $amp = Equipment::factory()->create([
        'type' => 'amplifier',
        'make' => 'Alpha',
        'model' => '8410',
        'value_usd' => 2500.00,
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $amp->id,
        'event_id' => $this->event->id,
        'station_id' => $this->station->id,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    $component = Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id]);

    // Verify the count and value are calculated correctly
    expect($component->get('assignedCount'))->toBe(1);
    expect($component->get('assignedTotalValue'))->toBe(2500.00);
});

test('handles drop event from drag and drop', function () {
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Hustler',
        'model' => '4BTV',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => null,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->dispatch('equipment-dropped', equipmentId: $antenna->id, fromCatalog: false)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $antenna->id,
        'station_id' => $this->station->id,
    ]);
});

test('requires manage-stations permission', function () {
    $userWithoutPermission = User::factory()->create();
    $this->actingAs($userWithoutPermission);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->assertForbidden();
});

test('groups assigned equipment by type', function () {
    // Create multiple equipment types
    $antenna = Equipment::factory()->create(['type' => 'antenna', 'make' => 'Antenna', 'model' => '1', 'owner_user_id' => $this->user->id]);
    $amplifier = Equipment::factory()->create(['type' => 'amplifier', 'make' => 'Amp', 'model' => '1', 'owner_user_id' => $this->user->id]);
    $computer = Equipment::factory()->create(['type' => 'computer', 'make' => 'PC', 'model' => '1', 'owner_user_id' => $this->user->id]);

    foreach ([$antenna, $amplifier, $computer] as $eq) {
        EquipmentEvent::create([
            'equipment_id' => $eq->id,
            'event_id' => $this->event->id,
            'station_id' => $this->station->id,
            'status' => 'committed',
            'committed_at' => now(),
            'status_changed_at' => now(),
        ]);
    }

    $component = Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id]);

    // Check that equipment is grouped by type
    expect($component->get('assignedEquipmentByType'))->toHaveKeys(['antenna', 'amplifier', 'computer']);
});

test('shows status badges for assigned equipment', function () {
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Status Test',
        'model' => 'Antenna',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => $this->station->id,
        'status' => 'delivered',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->assertSee('Delivered');
});

// Validation Method Tests

test('checkEquipmentConflict detects no conflict for unassigned equipment', function () {
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Test',
        'model' => 'Antenna',
        'owner_user_id' => $this->user->id,
    ]);

    $component = Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])->instance();

    $result = $component->checkEquipmentConflict($antenna->id, $this->station->id);

    expect($result)->toHaveKey('is_conflicted', false);
    expect($result)->toHaveKey('can_reassign', true);
    expect($result)->toHaveKey('conflict_message', '');
});

test('checkEquipmentConflict detects conflict with committed equipment', function () {
    // Create another station
    $anotherRadio = Equipment::factory()->create(['type' => 'radio', 'owner_user_id' => $this->user->id]);
    $anotherStation = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $anotherRadio->id,
        'name' => 'Other Station',
    ]);

    // Create antenna assigned to another station
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Conflict Test',
        'model' => 'Antenna',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => $anotherStation->id,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
        'assigned_by_user_id' => $this->user->id,
    ]);

    $component = Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])->instance();

    $result = $component->checkEquipmentConflict($antenna->id, $this->station->id);

    expect($result)->toHaveKey('is_conflicted', true);
    expect($result)->toHaveKey('can_reassign', true);
    expect($result)->toHaveKey('current_station_name', 'Other Station');
    expect($result['conflict_message'])->toContain('Other Station');
});

test('checkEquipmentConflict always allows reassignment', function () {
    // Create another station
    $anotherRadio = Equipment::factory()->create(['type' => 'radio', 'owner_user_id' => $this->user->id]);
    $anotherStation = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $anotherRadio->id,
        'name' => 'Active Station',
    ]);

    $computer = Equipment::factory()->create([
        'type' => 'computer',
        'make' => 'Conflict Test',
        'model' => 'PC',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $computer->id,
        'event_id' => $this->event->id,
        'station_id' => $anotherStation->id,
        'status' => 'delivered',
        'committed_at' => now(),
        'status_changed_at' => now(),
        'assigned_by_user_id' => $this->user->id,
    ]);

    $component = Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])->instance();

    $result = $component->checkEquipmentConflict($computer->id, $this->station->id);

    expect($result)->toHaveKey('is_conflicted', true);
    expect($result)->toHaveKey('can_reassign', true);
    expect($result['conflict_message'])->toContain('Active Station');
});

test('validateBandCompatibility passes for compatible antenna', function () {
    $band = Band::factory()->create([
        'name' => '20m',
        'meters' => 20,
        'is_hf' => true,
    ]);

    // Attach band to primary radio
    $this->radio->bands()->attach($band);

    // Create antenna with same band
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Compatible',
        'model' => 'Antenna',
        'owner_user_id' => $this->user->id,
    ]);
    $antenna->bands()->attach($band);

    $component = new EquipmentAssignment;
    $component->stationId = $this->station->id;
    $component->mount($this->station->id);

    $result = $component->validateBandCompatibility($antenna->id, $this->station->id);

    expect($result)->toHaveKey('compatible', true);
    expect($result)->toHaveKey('warning_message', null);
});

test('validateBandCompatibility warns for incompatible antenna', function () {
    $hfBand = Band::factory()->create([
        'name' => '20m',
        'meters' => 20,
        'is_hf' => true,
    ]);

    $vhfBand = Band::factory()->create([
        'name' => '2m',
        'meters' => 2,
        'is_vhf_uhf' => true,
    ]);

    // Radio supports HF
    $this->radio->bands()->attach($hfBand);

    // Antenna only supports VHF
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Incompatible',
        'model' => 'Antenna',
        'owner_user_id' => $this->user->id,
    ]);
    $antenna->bands()->attach($vhfBand);

    $component = new EquipmentAssignment;
    $component->stationId = $this->station->id;
    $component->mount($this->station->id);

    $result = $component->validateBandCompatibility($antenna->id, $this->station->id);

    expect($result)->toHaveKey('compatible', false);
    expect($result['warning_message'])->toContain('may not be compatible');
});

test('validateBandCompatibility passes for non-antenna equipment', function () {
    $amplifier = Equipment::factory()->create([
        'type' => 'amplifier',
        'make' => 'Test',
        'model' => 'Amp',
        'owner_user_id' => $this->user->id,
    ]);

    $component = new EquipmentAssignment;
    $component->stationId = $this->station->id;
    $component->mount($this->station->id);

    $result = $component->validateBandCompatibility($amplifier->id, $this->station->id);

    expect($result)->toHaveKey('compatible', true);
    expect($result)->toHaveKey('warning_message', null);
});

test('validatePowerLimits passes for amplifier within station limit', function () {
    // Station max power is 150W (from default or config)
    $this->station->update(['max_power_watts' => 200]);

    $amplifier = Equipment::factory()->create([
        'type' => 'amplifier',
        'make' => 'Within Limit',
        'model' => 'Amp',
        'power_output_watts' => 150,
        'owner_user_id' => $this->user->id,
    ]);

    $component = new EquipmentAssignment;
    $component->stationId = $this->station->id;
    $component->mount($this->station->id);

    $result = $component->validatePowerLimits($amplifier->id, $this->station->id);

    expect($result)->toHaveKey('within_limits', true);
    expect($result)->toHaveKey('warning_message', null);
    expect($result)->toHaveKey('calculated_power', 150);
});

test('validatePowerLimits warns when amplifier exceeds station limit', function () {
    $this->station->update(['max_power_watts' => 100]);

    $amplifier = Equipment::factory()->create([
        'type' => 'amplifier',
        'make' => 'Over Limit',
        'model' => 'Amp',
        'power_output_watts' => 200,
        'owner_user_id' => $this->user->id,
    ]);

    $component = new EquipmentAssignment;
    $component->stationId = $this->station->id;
    $component->mount($this->station->id);

    $result = $component->validatePowerLimits($amplifier->id, $this->station->id);

    expect($result)->toHaveKey('within_limits', false);
    expect($result['warning_message'])->toContain('exceeds station limit');
    expect($result)->toHaveKey('calculated_power', 200);
});

test('validatePowerLimits warns when amplifier exceeds operating class limit', function () {
    // Operating class max is 150W from beforeEach
    $amplifier = Equipment::factory()->create([
        'type' => 'amplifier',
        'make' => 'Over Class Limit',
        'model' => 'Amp',
        'power_output_watts' => 200,
        'owner_user_id' => $this->user->id,
    ]);

    $component = new EquipmentAssignment;
    $component->stationId = $this->station->id;
    $component->mount($this->station->id);

    $result = $component->validatePowerLimits($amplifier->id, $this->station->id);

    expect($result)->toHaveKey('within_limits', false);
    expect($result['warning_message'])->toContain('Class 3A limit');
    expect($result)->toHaveKey('calculated_power', 200);
});

test('validatePowerLimits passes for non-amplifier equipment', function () {
    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Test',
        'model' => 'Antenna',
        'owner_user_id' => $this->user->id,
    ]);

    $component = new EquipmentAssignment;
    $component->stationId = $this->station->id;
    $component->mount($this->station->id);

    $result = $component->validatePowerLimits($antenna->id, $this->station->id);

    expect($result)->toHaveKey('within_limits', true);
    expect($result)->toHaveKey('warning_message', null);
});

test('validateEquipmentType rejects radio as additional equipment', function () {
    $radio = Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Test',
        'model' => 'Radio',
        'owner_user_id' => $this->user->id,
    ]);

    $component = new EquipmentAssignment;
    $component->stationId = $this->station->id;
    $component->mount($this->station->id);

    $result = $component->validateEquipmentType($radio->id, 'assignment');

    expect($result)->toHaveKey('valid', false);
    expect($result['error_message'])->toContain('primary radio');
});

test('validateEquipmentType accepts valid equipment types', function () {
    $types = ['antenna', 'amplifier', 'computer', 'accessory', 'other'];

    foreach ($types as $type) {
        $equipment = Equipment::factory()->create([
            'type' => $type,
            'make' => 'Test',
            'model' => ucfirst($type),
            'owner_user_id' => $this->user->id,
        ]);

        $component = new EquipmentAssignment;
        $component->stationId = $this->station->id;
        $component->mount($this->station->id);

        $result = $component->validateEquipmentType($equipment->id, 'assignment');

        expect($result)->toHaveKey('valid', true);
        expect($result)->toHaveKey('error_message', null);
    }
});

test('shows warning modal for incompatible antenna bands', function () {
    $hfBand = Band::factory()->create(['name' => '20m', 'meters' => 20, 'is_hf' => true]);
    $vhfBand = Band::factory()->create(['name' => '2m', 'meters' => 2, 'is_vhf_uhf' => true]);

    $this->radio->bands()->attach($hfBand);

    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'VHF Only',
        'model' => 'Antenna',
        'owner_user_id' => $this->user->id,
    ]);
    $antenna->bands()->attach($vhfBand);

    EquipmentEvent::create([
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => null,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $antenna->id, false)
        ->assertSet('showWarningModal', true)
        ->assertSet('pendingEquipmentId', $antenna->id);

    // Equipment should NOT be assigned yet
    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $antenna->id,
        'station_id' => null,
    ]);
});

test('shows warning modal for amplifier exceeding power limits', function () {
    $this->station->update(['max_power_watts' => 100]);

    $amplifier = Equipment::factory()->create([
        'type' => 'amplifier',
        'make' => 'Overpowered',
        'model' => 'Amp',
        'power_output_watts' => 500,
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $amplifier->id,
        'event_id' => $this->event->id,
        'station_id' => null,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $amplifier->id, false)
        ->assertSet('showWarningModal', true)
        ->assertSet('pendingEquipmentId', $amplifier->id);

    // Equipment should NOT be assigned yet
    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $amplifier->id,
        'station_id' => null,
    ]);
});

test('confirming warning modal proceeds with assignment', function () {
    $hfBand = Band::factory()->create(['name' => '20m', 'meters' => 20, 'is_hf' => true]);
    $vhfBand = Band::factory()->create(['name' => '2m', 'meters' => 2, 'is_vhf_uhf' => true]);

    $this->radio->bands()->attach($hfBand);

    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Warn Then Assign',
        'model' => 'Antenna',
        'owner_user_id' => $this->user->id,
    ]);
    $antenna->bands()->attach($vhfBand);

    EquipmentEvent::create([
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => null,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $antenna->id, false)
        ->assertSet('showWarningModal', true)
        ->call('confirmWarningAssignment')
        ->assertSet('showWarningModal', false)
        ->assertSet('pendingEquipmentId', null)
        ->assertDispatched('toast');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $antenna->id,
        'station_id' => $this->station->id,
    ]);
});

test('cancelling warning modal does not assign equipment', function () {
    $hfBand = Band::factory()->create(['name' => '20m', 'meters' => 20, 'is_hf' => true]);
    $vhfBand = Band::factory()->create(['name' => '2m', 'meters' => 2, 'is_vhf_uhf' => true]);

    $this->radio->bands()->attach($hfBand);

    $antenna = Equipment::factory()->create([
        'type' => 'antenna',
        'make' => 'Cancelled',
        'model' => 'Antenna',
        'owner_user_id' => $this->user->id,
    ]);
    $antenna->bands()->attach($vhfBand);

    EquipmentEvent::create([
        'equipment_id' => $antenna->id,
        'event_id' => $this->event->id,
        'station_id' => null,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $antenna->id, false)
        ->assertSet('showWarningModal', true)
        ->call('cancelWarningAssignment')
        ->assertSet('showWarningModal', false)
        ->assertSet('pendingEquipmentId', null);

    // Equipment should remain unassigned
    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $antenna->id,
        'station_id' => null,
    ]);
});

test('validation integration shows conflict modal for equipment assigned to another station', function () {
    // Create another station with committed equipment
    $anotherRadio = Equipment::factory()->create(['type' => 'radio', 'owner_user_id' => $this->user->id]);
    $anotherStation = Station::factory()->create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $anotherRadio->id,
        'name' => 'Busy Station',
    ]);

    $amplifier = Equipment::factory()->create([
        'type' => 'amplifier',
        'make' => 'Busy',
        'model' => 'Amp',
        'owner_user_id' => $this->user->id,
    ]);

    EquipmentEvent::create([
        'equipment_id' => $amplifier->id,
        'event_id' => $this->event->id,
        'station_id' => $anotherStation->id,
        'status' => 'delivered',
        'committed_at' => now(),
        'status_changed_at' => now(),
        'assigned_by_user_id' => $this->user->id,
    ]);

    // Attempt to assign should show conflict modal (reassignable)
    Livewire::test(EquipmentAssignment::class, ['stationId' => $this->station->id])
        ->call('assignEquipment', $amplifier->id, false)
        ->assertSet('showConflictModal', true)
        ->assertSet('conflictEquipmentId', $amplifier->id);

    // Verify equipment was NOT reassigned yet (pending confirmation)
    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $amplifier->id,
        'event_id' => $this->event->id,
        'station_id' => $anotherStation->id, // Still on original station
    ]);
});
