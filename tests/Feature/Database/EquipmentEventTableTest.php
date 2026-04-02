<?php

use App\Models\Equipment;
use App\Models\Event;
use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

test('equipment_event table exists', function () {
    expect(Schema::hasTable('equipment_event'))->toBeTrue();
});

test('equipment_event table has all required columns', function () {
    $columns = [
        'id',
        'equipment_id',
        'event_id',
        'station_id',
        'assigned_by_user_id',
        'status',
        'committed_at',
        'expected_delivery_at',
        'delivery_notes',
        'manager_notes',
        'status_changed_at',
        'status_changed_by_user_id',
        'created_at',
        'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('equipment_event', $column))->toBeTrue();
    }
});

test('can create equipment_event record with all required relationships', function () {
    $user = User::factory()->create();
    $equipment = Equipment::factory()->create(['owner_user_id' => $user->id]);
    $event = Event::factory()->create();
    $eventConfig = \App\Models\EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);

    $pivotData = [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'station_id' => $station->id,
        'assigned_by_user_id' => $user->id,
        'status' => 'committed',
        'delivery_notes' => 'Test delivery notes',
        'manager_notes' => 'Test manager notes',
        'status_changed_by_user_id' => $user->id,
    ];

    $result = \DB::table('equipment_event')->insert($pivotData);

    expect($result)->toBeTrue();

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);
});

test('unique constraint prevents duplicate equipment commitments per event', function () {
    $user = User::factory()->create();
    $equipment = Equipment::factory()->create(['owner_user_id' => $user->id]);
    $event = Event::factory()->create();

    $pivotData = [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ];

    \DB::table('equipment_event')->insert($pivotData);

    // Attempt to insert duplicate should throw exception
    expect(fn () => \DB::table('equipment_event')->insert($pivotData))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('cascades delete when equipment is deleted', function () {
    $user = User::factory()->create();
    $equipment = Equipment::factory()->create(['owner_user_id' => $user->id]);
    $event = Event::factory()->create();

    \DB::table('equipment_event')->insert([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    $equipment->forceDelete();

    $this->assertDatabaseMissing('equipment_event', [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
    ]);
});

test('cascades delete when event is deleted', function () {
    $user = User::factory()->create();
    $equipment = Equipment::factory()->create(['owner_user_id' => $user->id]);
    $event = Event::factory()->create();

    \DB::table('equipment_event')->insert([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    $event->forceDelete();

    $this->assertDatabaseMissing('equipment_event', [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
    ]);
});

test('nulls station_id when station is deleted', function () {
    $user = User::factory()->create();
    $equipment = Equipment::factory()->create(['owner_user_id' => $user->id]);
    $event = Event::factory()->create();
    $eventConfig = \App\Models\EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create(['event_configuration_id' => $eventConfig->id]);

    \DB::table('equipment_event')->insert([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'station_id' => $station->id,
        'status' => 'committed',
    ]);

    $station->forceDelete();

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'station_id' => null,
    ]);
});

test('nulls assigned_by_user_id when user is force deleted', function () {
    $owner = User::factory()->create();
    $assignedBy = User::factory()->create();
    $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
    $event = Event::factory()->create();

    \DB::table('equipment_event')->insert([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'assigned_by_user_id' => $assignedBy->id,
        'status' => 'committed',
    ]);

    $assignedBy->forceDelete();

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'assigned_by_user_id' => null,
    ]);
});

test('status enum accepts all valid values', function () {
    $user = User::factory()->create();
    $equipment = Equipment::factory()->create(['owner_user_id' => $user->id]);
    $event = Event::factory()->create();

    $validStatuses = [
        'committed',
        'delivered',
        'returned',
        'cancelled',
        'lost',
        'damaged',
    ];

    foreach ($validStatuses as $status) {
        \DB::table('equipment_event')->insert([
            'equipment_id' => $equipment->id,
            'event_id' => Event::factory()->create()->id,
            'status' => $status,
        ]);

        $this->assertDatabaseHas('equipment_event', [
            'equipment_id' => $equipment->id,
            'status' => $status,
        ]);
    }
});

test('default status is committed', function () {
    $user = User::factory()->create();
    $equipment = Equipment::factory()->create(['owner_user_id' => $user->id]);
    $event = Event::factory()->create();

    \DB::table('equipment_event')->insert([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
    ]);

    $this->assertDatabaseHas('equipment_event', [
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);
});
