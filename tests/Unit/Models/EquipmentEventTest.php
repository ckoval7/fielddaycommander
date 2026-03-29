<?php

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\Station;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create([
        'start_time' => now()->addDays(7),
        'end_time' => now()->addDays(9),
    ]);
    $this->event->load('eventConfiguration');
    $this->equipment = Equipment::factory()->create([
        'owner_user_id' => $this->user->id,
    ]);
});

// Status Transition Tests
test('canTransitionTo validates committed can transition to delivered or cancelled', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    expect($commitment->canTransitionTo('delivered'))->toBeTrue();
    expect($commitment->canTransitionTo('cancelled'))->toBeTrue();
    expect($commitment->canTransitionTo('in_use'))->toBeFalse();
    expect($commitment->canTransitionTo('returned'))->toBeFalse();
    expect($commitment->canTransitionTo('lost'))->toBeFalse();
    expect($commitment->canTransitionTo('damaged'))->toBeFalse();
});

test('canTransitionTo validates delivered can transition to in_use, returned, cancelled, lost, or damaged', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'delivered',
    ]);

    expect($commitment->canTransitionTo('in_use'))->toBeTrue();
    expect($commitment->canTransitionTo('returned'))->toBeTrue();
    expect($commitment->canTransitionTo('cancelled'))->toBeTrue();
    expect($commitment->canTransitionTo('lost'))->toBeTrue();
    expect($commitment->canTransitionTo('damaged'))->toBeTrue();
    expect($commitment->canTransitionTo('committed'))->toBeFalse();
});

test('canTransitionTo validates in_use can transition to returned, lost, or damaged', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'in_use',
    ]);

    expect($commitment->canTransitionTo('returned'))->toBeTrue();
    expect($commitment->canTransitionTo('lost'))->toBeTrue();
    expect($commitment->canTransitionTo('damaged'))->toBeTrue();
    expect($commitment->canTransitionTo('committed'))->toBeFalse();
    expect($commitment->canTransitionTo('delivered'))->toBeFalse();
    expect($commitment->canTransitionTo('cancelled'))->toBeFalse();
});

test('canTransitionTo validates lost and damaged can transition to returned', function () {
    $lostCommitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'lost',
    ]);

    expect($lostCommitment->canTransitionTo('returned'))->toBeTrue();
    expect($lostCommitment->canTransitionTo('committed'))->toBeFalse();

    $damagedCommitment = EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $this->event->id,
        'status' => 'damaged',
    ]);

    expect($damagedCommitment->canTransitionTo('returned'))->toBeTrue();
    expect($damagedCommitment->canTransitionTo('in_use'))->toBeFalse();
});

test('canTransitionTo validates returned and cancelled are terminal states', function () {
    $returnedCommitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'returned',
    ]);

    expect($returnedCommitment->canTransitionTo('committed'))->toBeFalse();
    expect($returnedCommitment->canTransitionTo('delivered'))->toBeFalse();
    expect($returnedCommitment->canTransitionTo('in_use'))->toBeFalse();

    $cancelledCommitment = EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $this->event->id,
        'status' => 'cancelled',
    ]);

    expect($cancelledCommitment->canTransitionTo('committed'))->toBeFalse();
    expect($cancelledCommitment->canTransitionTo('delivered'))->toBeFalse();
});

// changeStatus Method Tests
test('changeStatus updates status and tracks who changed it', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    $result = $commitment->changeStatus('delivered', $this->user);

    expect($result)->toBeTrue();
    $commitment->refresh();
    expect($commitment->status)->toBe('delivered');
    expect($commitment->status_changed_by_user_id)->toBe($this->user->id);
    expect($commitment->status_changed_at)->not->toBeNull();
});

test('changeStatus rejects invalid transitions', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'returned',
    ]);

    $result = $commitment->changeStatus('committed', $this->user);

    expect($result)->toBeFalse();
    $commitment->refresh();
    expect($commitment->status)->toBe('returned'); // Status unchanged
});

test('changeStatus stores manager notes when provided', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'delivered',
    ]);

    $commitment->changeStatus('lost', $this->user, 'Equipment missing after teardown');

    $commitment->refresh();
    expect($commitment->status)->toBe('lost');
    expect($commitment->manager_notes)->toContain('Equipment missing after teardown');
});

// isOverlapping Tests
test('isOverlapping detects overlapping event dates', function () {
    $overlappingEvent = Event::factory()->create([
        'start_time' => now()->addDays(8), // Overlaps with original event (day 7-9)
        'end_time' => now()->addDays(10),
    ]);

    // Create an active commitment for the same equipment at the overlapping event
    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $overlappingEvent->id,
        'status' => 'committed',
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    expect($commitment->isOverlapping($this->event->id))->toBeTrue();
});

test('isOverlapping returns false for non-overlapping events', function () {
    $nonOverlappingEvent = Event::factory()->create([
        'start_time' => now()->addDays(20),
        'end_time' => now()->addDays(22),
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    expect($commitment->isOverlapping($nonOverlappingEvent->id))->toBeFalse();
});

test('isOverlapping ignores cancelled commitments', function () {
    $overlappingEvent = Event::factory()->create([
        'start_time' => now()->addDays(8),
        'end_time' => now()->addDays(10),
    ]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'cancelled', // Cancelled, should not cause overlap
    ]);

    expect($commitment->isOverlapping($overlappingEvent->id))->toBeFalse();
});

// Scope Tests
test('forEvent scope filters by event', function () {
    $otherEvent = Event::factory()->create();

    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $otherEvent->id,
    ]);

    $results = EquipmentEvent::forEvent($this->event->id)->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->event_id)->toBe($this->event->id);
});

test('withStatus scope filters by status', function () {
    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $this->event->id,
        'status' => 'delivered',
    ]);

    $results = EquipmentEvent::withStatus('delivered')->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->status)->toBe('delivered');
});

test('hasIssues scope returns lost and damaged equipment', function () {
    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'lost',
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $this->event->id,
        'status' => 'damaged',
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $this->event->id,
        'status' => 'returned',
    ]);

    $results = EquipmentEvent::hasIssues()->get();

    expect($results)->toHaveCount(2);
    expect($results->pluck('status')->toArray())->toContain('lost', 'damaged');
});

test('needsReturn scope returns delivered and in_use equipment', function () {
    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'delivered',
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $this->event->id,
        'status' => 'in_use',
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $this->event->id,
        'status' => 'returned',
    ]);

    $results = EquipmentEvent::needsReturn()->get();

    expect($results)->toHaveCount(2);
    expect($results->pluck('status')->toArray())->toContain('delivered', 'in_use');
});

test('byOwner scope filters by equipment owner', function () {
    $otherUser = User::factory()->create();

    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id, // Owned by $this->user
        'event_id' => $this->event->id,
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $otherUser->id])->id,
        'event_id' => $this->event->id,
    ]);

    $results = EquipmentEvent::byOwner($this->user->id)->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->equipment->owner_user_id)->toBe($this->user->id);
});

test('assignedToStation scope filters by station assignment', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->event->eventConfiguration->id ?? \App\Models\EventConfiguration::factory()->create(['event_id' => $this->event->id])->id]);

    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'station_id' => $station->id,
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $this->event->id,
        'station_id' => null, // Unassigned
    ]);

    $results = EquipmentEvent::assignedToStation($station->id)->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->station_id)->toBe($station->id);
});

// assignToStation Method Test
test('assignToStation assigns equipment and updates status to in_use', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->event->eventConfiguration->id ?? \App\Models\EventConfiguration::factory()->create(['event_id' => $this->event->id])->id]);

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'delivered',
    ]);

    $result = $commitment->assignToStation($station->id, $this->user);

    expect($result)->toBeTrue();
    $commitment->refresh();
    expect($commitment->station_id)->toBe($station->id);
    expect($commitment->status)->toBe('in_use');
    expect($commitment->assigned_by_user_id)->toBe($this->user->id);
});
