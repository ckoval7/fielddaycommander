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

it('defines valid statuses without in_use', function () {
    expect(EquipmentEvent::STATUSES)->toBe([
        'committed',
        'delivered',
        'returned',
        'cancelled',
        'lost',
        'damaged',
    ]);
});

// Status Validation Tests
test('canTransitionTo allows any valid status from any state', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'returned',
    ]);

    foreach (EquipmentEvent::STATUSES as $status) {
        expect($commitment->canTransitionTo($status))->toBeTrue();
    }
});

test('canTransitionTo rejects invalid status values', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'committed',
    ]);

    expect($commitment->canTransitionTo('invalid'))->toBeFalse();
    expect($commitment->canTransitionTo(''))->toBeFalse();
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

test('changeStatus allows any valid status from any state', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'returned',
    ]);

    $result = $commitment->changeStatus('committed', $this->user);

    expect($result)->toBeTrue();
    $commitment->refresh();
    expect($commitment->status)->toBe('committed');
});

test('changeStatus rejects invalid status values', function () {
    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'returned',
    ]);

    $result = $commitment->changeStatus('bogus', $this->user);

    expect($result)->toBeFalse();
    $commitment->refresh();
    expect($commitment->status)->toBe('returned');
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

test('needsReturn scope returns delivered equipment', function () {
    EquipmentEvent::factory()->create([
        'equipment_id' => $this->equipment->id,
        'event_id' => $this->event->id,
        'status' => 'delivered',
    ]);

    EquipmentEvent::factory()->create([
        'equipment_id' => Equipment::factory()->create(['owner_user_id' => $this->user->id])->id,
        'event_id' => $this->event->id,
        'status' => 'returned',
    ]);

    $results = EquipmentEvent::needsReturn()->get();

    expect($results)->toHaveCount(1);
    expect($results->pluck('status')->toArray())->toContain('delivered');
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
