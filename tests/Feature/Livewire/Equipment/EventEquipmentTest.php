<?php

use App\Livewire\Equipment\EventEquipment;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Create an upcoming event
    $this->event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
        'setup_allowed_from' => now()->addDays(6),
    ]);

    // Create user's equipment
    $this->equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);
});

test('component mounts with first upcoming event selected', function () {
    Livewire::test(EventEquipment::class)
        ->assertSet('selectedEventId', $this->event->id);
});

test('component displays upcoming events', function () {
    Livewire::test(EventEquipment::class)
        ->assertSee($this->event->name);
});

test('component displays user equipment', function () {
    Livewire::test(EventEquipment::class)
        ->assertSee($this->equipment->make)
        ->assertSee($this->equipment->model);
});

test('user can open commit modal', function () {
    Livewire::test(EventEquipment::class)
        ->call('openCommitModal')
        ->assertSet('showCommitModal', true)
        ->assertSet('equipmentId', null)
        ->assertSet('expectedDeliveryAt', null)
        ->assertSet('deliveryNotes', null);
});

test('user can commit equipment to event', function () {
    Livewire::test(EventEquipment::class)
        ->set('showCommitModal', true)
        ->set('equipmentId', $this->equipment->id)
        ->set('expectedDeliveryAt', now()->addDays(7)->format('Y-m-d H:i:s'))
        ->set('deliveryNotes', 'Test delivery notes')
        ->call('commitEquipment')
        ->assertSet('showCommitModal', false)
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'delivery_notes' => 'Test delivery notes',
    ]);
});

test('cannot commit equipment without selecting equipment', function () {
    Livewire::test(EventEquipment::class)
        ->set('showCommitModal', true)
        ->call('commitEquipment')
        ->assertHasErrors(['equipmentId']);
});

test('cannot commit equipment user does not own', function () {
    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    Livewire::test(EventEquipment::class)
        ->set('showCommitModal', true)
        ->set('equipmentId', $otherEquipment->id)
        ->call('commitEquipment')
        ->assertHasErrors(['equipmentId']);
});

test('validates expected delivery is within event dates', function () {
    Livewire::test(EventEquipment::class)
        ->set('showCommitModal', true)
        ->set('equipmentId', $this->equipment->id)
        ->set('expectedDeliveryAt', now()->addDays(1)->format('Y-m-d H:i:s')) // Before setup allowed
        ->call('commitEquipment')
        ->assertHasErrors(['expectedDeliveryAt']);
});

test('validates delivery notes max length', function () {
    Livewire::test(EventEquipment::class)
        ->set('showCommitModal', true)
        ->set('equipmentId', $this->equipment->id)
        ->set('deliveryNotes', str_repeat('a', 501))
        ->call('commitEquipment')
        ->assertHasErrors(['deliveryNotes']);
});

test('prevents overlapping commitments', function () {
    // Create an overlapping event
    $overlappingEvent = Event::factory()->create([
        'start_time' => now()->addDays(8), // Overlaps with $this->event
        'end_time' => now()->addDays(10),
        'setup_allowed_from' => now()->addDays(7),
    ]);

    // Commit equipment to first event
    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    // Try to commit to overlapping event
    Livewire::test(EventEquipment::class)
        ->set('selectedEventId', $overlappingEvent->id)
        ->set('showCommitModal', true)
        ->set('equipmentId', $this->equipment->id)
        ->call('commitEquipment')
        ->assertHasErrors(['equipmentId']);
});

test('allows commitment to non-overlapping events', function () {
    // Create a non-overlapping event
    $futureEvent = Event::factory()->create([
        'start_time' => now()->addDays(20),
        'end_time' => now()->addDays(22),
        'setup_allowed_from' => now()->addDays(19),
    ]);

    // Commit equipment to first event
    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    // Should be able to commit to non-overlapping event
    Livewire::test(EventEquipment::class)
        ->set('selectedEventId', $futureEvent->id)
        ->set('showCommitModal', true)
        ->set('equipmentId', $this->equipment->id)
        ->call('commitEquipment')
        ->assertSet('showCommitModal', false);

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $this->equipment->id,
        'event_id' => $futureEvent->id,
        'status' => 'committed',
    ]);
});

test('user can mark commitment as delivered', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    Livewire::test(EventEquipment::class)
        ->call('markAsDelivered', $commitment->id)
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'status' => 'delivered',
    ]);
});

test('cannot mark as delivered if not owner', function () {
    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $otherEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    Livewire::test(EventEquipment::class)
        ->call('markAsDelivered', $commitment->id)
        ->assertDispatched('notify');

    // Status should not change
    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'status' => 'committed',
    ]);
});

test('cannot mark as delivered if invalid transition', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'returned', // Cannot transition from returned to delivered
    ]);

    Livewire::test(EventEquipment::class)
        ->call('markAsDelivered', $commitment->id)
        ->assertDispatched('notify');

    // Status should not change
    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'status' => 'returned',
    ]);
});

test('user can cancel commitment', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    Livewire::test(EventEquipment::class)
        ->call('cancelCommitment', $commitment->id)
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'status' => 'cancelled',
    ]);
});

test('cannot cancel if not owner', function () {
    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $otherEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    Livewire::test(EventEquipment::class)
        ->call('cancelCommitment', $commitment->id)
        ->assertDispatched('notify');

    // Status should not change
    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'status' => 'committed',
    ]);
});

test('cannot cancel equipment in use', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'in_use',
    ]);

    Livewire::test(EventEquipment::class)
        ->call('cancelCommitment', $commitment->id)
        ->assertDispatched('notify');

    // Status should not change
    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'status' => 'in_use',
    ]);
});

test('user can update delivery notes', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'delivery_notes' => 'Original notes',
    ]);

    Livewire::test(EventEquipment::class)
        ->call('updateNotes', $commitment->id, 'Updated notes')
        ->assertDispatched('notify');

    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'delivery_notes' => 'Updated notes',
    ]);
});

test('cannot update notes if not owner', function () {
    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $otherEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'delivery_notes' => 'Original notes',
    ]);

    Livewire::test(EventEquipment::class)
        ->call('updateNotes', $commitment->id, 'Updated notes')
        ->assertDispatched('notify');

    // Notes should not change
    $this->assertDatabaseHas('equipment_event', [
        'id' => $commitment->id,
        'delivery_notes' => 'Original notes',
    ]);
});

test('displays commitments for selected event', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'delivery_notes' => 'Test notes',
    ]);

    Livewire::test(EventEquipment::class)
        ->assertSee('Test notes');
});

test('does not display commitments from other events', function () {
    // Create another event
    $otherEvent = Event::factory()->create([
        'start_time' => now()->addDays(20),
        'end_time' => now()->addDays(22),
        'setup_allowed_from' => now()->addDays(19),
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $otherEvent->id,
        'status' => 'committed',
        'delivery_notes' => 'Other event notes',
    ]);

    Livewire::test(EventEquipment::class)
        ->set('selectedEventId', $this->event->id)
        ->assertDontSee('Other event notes');
});

test('only shows events within next 30 days or currently active', function () {
    // Create a far future event (should not show)
    $farFutureEvent = Event::factory()->create([
        'name' => 'Future Field Day Event',
        'start_time' => now()->addDays(40),
        'end_time' => now()->addDays(42),
        'setup_allowed_from' => now()->addDays(39),
    ]);

    Livewire::test(EventEquipment::class)
        ->assertSee($this->event->name)
        ->assertDontSee($farFutureEvent->name);
});

test('detects currently active event using appNow for developer mode time travel', function () {
    // Create an event that is "future" in real time but "active" in dev mode time
    $futureEvent = Event::factory()->create([
        'name' => 'Dev Mode Active Event',
        'start_time' => now()->addDays(10),
        'end_time' => now()->addDays(12),
        'setup_allowed_from' => now()->addDays(9),
    ]);

    // Simulate developer mode time travel to make the future event "active"
    app(\App\Services\DeveloperClockService::class)->setFakeTime(now()->addDays(11));

    // The component should detect this as an active event
    Livewire::test(EventEquipment::class)
        ->assertSet('selectedEventId', $futureEvent->id)
        ->assertSee($futureEvent->name);
});

test('openNotesModal loads commitment data and shows modal', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'delivery_notes' => 'Existing notes for modal',
    ]);

    Livewire::test(EventEquipment::class)
        ->call('openNotesModal', $commitment->id)
        ->assertSet('showNotesModal', true)
        ->assertSet('updateNoteId', $commitment->id)
        ->assertSet('tempNotes', 'Existing notes for modal');
});

test('openNotesModal rejects commitment not owned by user', function () {
    $otherEquipment = Equipment::factory()->create([
        'owner_user_id' => User::factory()->create()->id,
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $otherEquipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    Livewire::test(EventEquipment::class)
        ->call('openNotesModal', $commitment->id)
        ->assertSet('showNotesModal', false)
        ->assertDispatched('notify');
});

test('first tab is active on page load showing event details and commitments', function () {
    // Create a commitment for the upcoming event
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
        'delivery_notes' => 'Initial load test notes',
    ]);

    $component = Livewire::test(EventEquipment::class);

    // Should have selected event ID set
    $component->assertSet('selectedEventId', $this->event->id);

    // Should see event details (which are inside the first tab)
    $component->assertSee($this->event->name)
        ->assertSee('Event Details')
        ->assertSee('My Commitments')
        ->assertSee('Initial load test notes');
});
