<?php

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->event = Event::factory()->create([
        'is_active' => true,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->eventConfig = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    $this->radio = Equipment::factory()->create([
        'type' => 'radio',
        'make' => 'Yaesu',
        'model' => 'FT-991A',
    ]);
});

test('creating a station with primary radio auto-commits radio to event', function () {
    $station = Station::create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Test Station',
        'is_gota' => false,
        'is_vhf_only' => false,
        'is_satellite' => false,
        'max_power_watts' => 100,
    ]);

    $commitment = EquipmentEvent::where('equipment_id', $this->radio->id)
        ->where('event_id', $this->event->id)
        ->first();

    expect($commitment)->not->toBeNull()
        ->and($commitment->station_id)->toBe($station->id)
        ->and($commitment->status)->toBe('committed');
});

test('creating a station without primary radio does not create commitment', function () {
    Station::create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => null,
        'name' => 'Test Station',
        'is_gota' => false,
        'is_vhf_only' => false,
        'is_satellite' => false,
        'max_power_watts' => 100,
    ]);

    expect(EquipmentEvent::where('event_id', $this->event->id)->count())->toBe(0);
});

test('updating station primary radio creates commitment for new radio', function () {
    $station = Station::create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => null,
        'name' => 'Test Station',
        'is_gota' => false,
        'is_vhf_only' => false,
        'is_satellite' => false,
        'max_power_watts' => 100,
    ]);

    $station->update(['radio_equipment_id' => $this->radio->id]);

    $commitment = EquipmentEvent::where('equipment_id', $this->radio->id)
        ->where('event_id', $this->event->id)
        ->first();

    expect($commitment)->not->toBeNull()
        ->and($commitment->station_id)->toBe($station->id)
        ->and($commitment->status)->toBe('committed');
});

test('changing primary radio unassigns old radio station and commits new radio', function () {
    $oldRadio = $this->radio;
    $newRadio = Equipment::factory()->create(['type' => 'radio']);

    $station = Station::create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $oldRadio->id,
        'name' => 'Test Station',
        'is_gota' => false,
        'is_vhf_only' => false,
        'is_satellite' => false,
        'max_power_watts' => 100,
    ]);

    // Verify old radio is committed
    $oldCommitment = EquipmentEvent::where('equipment_id', $oldRadio->id)
        ->where('event_id', $this->event->id)
        ->first();
    expect($oldCommitment->station_id)->toBe($station->id);

    // Change to new radio
    $station->update(['radio_equipment_id' => $newRadio->id]);

    // Old radio's station assignment should be cleared
    $oldCommitment->refresh();
    expect($oldCommitment->station_id)->toBeNull();

    // New radio should be committed with station assigned
    $newCommitment = EquipmentEvent::where('equipment_id', $newRadio->id)
        ->where('event_id', $this->event->id)
        ->first();
    expect($newCommitment)->not->toBeNull()
        ->and($newCommitment->station_id)->toBe($station->id);
});

test('assigning primary radio that already has commitment assigns station', function () {
    // Pre-existing commitment without station
    $commitment = EquipmentEvent::create([
        'equipment_id' => $this->radio->id,
        'event_id' => $this->event->id,
        'station_id' => null,
        'status' => 'committed',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    $station = Station::create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'Test Station',
        'is_gota' => false,
        'is_vhf_only' => false,
        'is_satellite' => false,
        'max_power_watts' => 100,
    ]);

    $commitment->refresh();
    expect($commitment->station_id)->toBe($station->id);

    // Should not create a duplicate
    expect(EquipmentEvent::where('equipment_id', $this->radio->id)
        ->where('event_id', $this->event->id)
        ->count())->toBe(1);
});

test('assigning primary radio that already has station assignment does not overwrite', function () {
    $otherStation = Station::create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => null,
        'name' => 'Other Station',
        'is_gota' => false,
        'is_vhf_only' => false,
        'is_satellite' => false,
        'max_power_watts' => 100,
    ]);

    // Pre-existing commitment already assigned to another station
    EquipmentEvent::create([
        'equipment_id' => $this->radio->id,
        'event_id' => $this->event->id,
        'station_id' => $otherStation->id,
        'status' => 'delivered',
        'committed_at' => now(),
        'status_changed_at' => now(),
    ]);

    // Create a new station with same radio as primary
    Station::create([
        'event_configuration_id' => $this->eventConfig->id,
        'radio_equipment_id' => $this->radio->id,
        'name' => 'New Station',
        'is_gota' => false,
        'is_vhf_only' => false,
        'is_satellite' => false,
        'max_power_watts' => 100,
    ]);

    // Should not overwrite existing station assignment
    $commitment = EquipmentEvent::where('equipment_id', $this->radio->id)
        ->where('event_id', $this->event->id)
        ->first();
    expect($commitment->station_id)->toBe($otherStation->id);
});
