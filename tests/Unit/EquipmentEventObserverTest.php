<?php

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\User;
use App\Notifications\Equipment\EquipmentCancelled;
use App\Notifications\Equipment\EquipmentCommitted;
use App\Notifications\Equipment\EquipmentDelivered;
use App\Notifications\Equipment\EquipmentIncident;
use App\Notifications\Equipment\EquipmentStatusChanged;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Notification::fake();

    // Create required permissions and roles for tests
    Permission::create(['name' => 'manage-event-equipment']);
    Role::create(['name' => 'System Administrator']);
});

test('observer sends notifications when equipment is committed', function () {
    $owner = User::factory()->create();
    $manager = User::factory()->create();
    $manager->givePermissionTo('manage-event-equipment');

    $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
    $event = Event::factory()->create();

    $equipmentEvent = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Notification::assertSentTo($owner, EquipmentCommitted::class, function ($notification) use ($equipmentEvent) {
        return $notification->equipmentEvent->id === $equipmentEvent->id
            && $notification->recipient === 'operator';
    });

    Notification::assertSentTo($manager, EquipmentCommitted::class, function ($notification) use ($equipmentEvent) {
        return $notification->equipmentEvent->id === $equipmentEvent->id
            && $notification->recipient === 'manager';
    });
});

test('observer sends notifications when equipment is delivered', function () {
    $owner = User::factory()->create();
    $manager = User::factory()->create();
    $manager->givePermissionTo('manage-event-equipment');

    $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
    $event = Event::factory()->create();

    $equipmentEvent = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Notification::fake();

    $equipmentEvent->status = 'delivered';
    $equipmentEvent->save();

    Notification::assertSentTo($owner, EquipmentDelivered::class);
    Notification::assertSentTo($manager, EquipmentDelivered::class);
});

test('observer sends notifications when equipment status changes to returned', function () {
    $owner = User::factory()->create();

    $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
    $event = Event::factory()->create();

    $equipmentEvent = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'delivered',
    ]);

    Notification::fake();

    $equipmentEvent->status = 'returned';
    $equipmentEvent->save();

    Notification::assertSentTo($owner, EquipmentStatusChanged::class, function ($notification) {
        return $notification->previousStatus === 'delivered';
    });
});

test('observer sends incident notifications when equipment is lost', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('System Administrator');
    $manager = User::factory()->create();
    $manager->givePermissionTo('manage-event-equipment');

    $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
    $event = Event::factory()->create();

    $equipmentEvent = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'delivered',
    ]);

    Notification::fake();

    $equipmentEvent->status = 'lost';
    $equipmentEvent->save();

    Notification::assertSentTo($owner, EquipmentIncident::class, function ($notification) {
        return $notification->incidentType === 'lost';
    });

    Notification::assertSentTo($admin, EquipmentIncident::class);
    Notification::assertSentTo($manager, EquipmentIncident::class);
});

test('observer sends incident notifications when equipment is damaged', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('System Administrator');
    $manager = User::factory()->create();
    $manager->givePermissionTo('manage-event-equipment');

    $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
    $event = Event::factory()->create();

    $equipmentEvent = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'delivered',
    ]);

    Notification::fake();

    $equipmentEvent->status = 'damaged';
    $equipmentEvent->save();

    Notification::assertSentTo($owner, EquipmentIncident::class, function ($notification) {
        return $notification->incidentType === 'damaged';
    });

    Notification::assertSentTo($admin, EquipmentIncident::class);
    Notification::assertSentTo($manager, EquipmentIncident::class);
});

test('observer sends cancellation notifications to managers only', function () {
    $owner = User::factory()->create();
    $manager = User::factory()->create();
    $manager->givePermissionTo('manage-event-equipment');

    $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
    $event = Event::factory()->create();

    $equipmentEvent = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Notification::fake();

    $equipmentEvent->status = 'cancelled';
    $equipmentEvent->save();

    Notification::assertSentTo($manager, EquipmentCancelled::class);
    Notification::assertNotSentTo($owner, EquipmentCancelled::class);
});

test('observer does not send notifications when non-status fields change', function () {
    $owner = User::factory()->create();

    $equipment = Equipment::factory()->create(['owner_user_id' => $owner->id]);
    $event = Event::factory()->create();

    $equipmentEvent = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'committed',
    ]);

    Notification::fake();

    $equipmentEvent->manager_notes = 'Updated notes';
    $equipmentEvent->save();

    Notification::assertNothingSent();
});
