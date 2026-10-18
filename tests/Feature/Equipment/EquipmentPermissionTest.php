<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Create all equipment permissions
    Permission::firstOrCreate(['name' => 'manage-own-equipment']);
    Permission::firstOrCreate(['name' => 'view-all-equipment']);
    Permission::firstOrCreate(['name' => 'manage-event-equipment']);
    Permission::firstOrCreate(['name' => 'edit-any-equipment']);

    $this->operator = User::factory()->create();
    $operatorRole = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $operatorRole->givePermissionTo('manage-own-equipment');
    $this->operator->assignRole($operatorRole);

    $this->eventManager = User::factory()->create();
    $managerRole = Role::firstOrCreate(['name' => 'Event Manager', 'guard_name' => 'web']);
    $managerRole->givePermissionTo(['manage-own-equipment', 'view-all-equipment', 'manage-event-equipment', 'edit-any-equipment']);
    $this->eventManager->assignRole($managerRole);
});

// manage-own-equipment Permission Tests
test('operator with manage-own-equipment can view their equipment', function () {
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
    ]);

    expect($this->operator->can('view', $equipment))->toBeTrue();
});

test('operator with manage-own-equipment can create equipment', function () {
    expect($this->operator->can('create', Equipment::class))->toBeTrue();
});

test('operator with manage-own-equipment can update their own equipment', function () {
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
    ]);

    expect($this->operator->can('update', $equipment))->toBeTrue();
});

test('operator with manage-own-equipment can delete their own equipment', function () {
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
    ]);

    expect($this->operator->can('delete', $equipment))->toBeTrue();
});

test('operator with manage-own-equipment cannot update others equipment', function () {
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->eventManager->id,
    ]);

    expect($this->operator->can('update', $equipment))->toBeFalse();
});

test('operator with manage-own-equipment cannot delete others equipment', function () {
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->eventManager->id,
    ]);

    expect($this->operator->can('delete', $equipment))->toBeFalse();
});

// view-all-equipment Permission Tests
test('event manager with view-all-equipment can view all equipment', function () {
    $operatorEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
    ]);

    expect($this->eventManager->can('view', $operatorEquipment))->toBeTrue();
});

// edit-any-equipment Permission Tests
test('event manager with edit-any-equipment can update any equipment', function () {
    $operatorEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
    ]);

    expect($this->eventManager->can('update', $operatorEquipment))->toBeTrue();
});

test('event manager with edit-any-equipment can delete any equipment', function () {
    $operatorEquipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
    ]);

    expect($this->eventManager->can('delete', $operatorEquipment))->toBeTrue();
});

// manage-event-equipment Permission Tests
test('operator without manage-event-equipment cannot change status to lost or damaged', function () {
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
    ]);

    $event = Event::factory()->create();

    $commitment = EquipmentEvent::factory()->create([
        'equipment_id' => $equipment->id,
        'event_id' => $event->id,
        'status' => 'delivered',
    ]);

    // Operator cannot change status to lost/damaged (only event managers can)
    expect($this->operator->can('changeStatus', $commitment))->toBeFalse();
});

// Equipment Commitment Authorization Tests
test('operator can commit their own equipment', function () {
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
    ]);

    expect($this->operator->can('commit', $equipment))->toBeTrue();
});

test('operator cannot commit others equipment', function () {
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->eventManager->id,
    ]);

    expect($this->operator->can('commit', $equipment))->toBeFalse();
});

test('event manager can commit any equipment', function () {
    $equipment = Equipment::factory()->create([
        'owner_user_id' => $this->operator->id,
    ]);

    expect($this->eventManager->can('commit', $equipment))->toBeTrue();
});

// Authenticated User Tests
test('all authenticated users can view equipment list', function () {
    $guest = User::factory()->create();

    expect($guest->can('viewAny', Equipment::class))->toBeTrue();
});

test('user without permissions cannot create equipment', function () {
    $guest = User::factory()->create();

    expect($guest->can('create', Equipment::class))->toBeFalse();
});
