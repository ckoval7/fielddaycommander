<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Station;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Run permission and role seeders
    $this->artisan('db:seed', ['--class' => 'PermissionSeeder']);
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    // Mark setup as complete to bypass setup wizard middleware
    DB::table('system_config')->updateOrInsert(
        ['key' => 'setup_completed'],
        ['value' => 'true']
    );
});

test('operators can view stations list', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $response = $this->actingAs($user)->get(route('stations.index'));

    $response->assertOk();
});

test('operators can view individual station', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $station = Station::factory()->create();

    expect($user->can('view', $station))->toBeTrue();
});

test('operators cannot create stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $response = $this->actingAs($user)->get(route('stations.create'));

    $response->assertForbidden();
});

test('operators cannot edit stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $station = Station::factory()->create();

    $response = $this->actingAs($user)->get(route('stations.edit', $station));

    $response->assertForbidden();
});

test('operators cannot delete stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Operator');

    $station = Station::factory()->create();

    expect($user->can('delete', $station))->toBeFalse();
});

test('event managers can view stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Event Manager');

    $response = $this->actingAs($user)->get(route('stations.index'));

    $response->assertOk();
});

test('event managers can create stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Event Manager');

    expect($user->can('create', Station::class))->toBeTrue();
});

test('event managers can edit stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Event Manager');

    $station = Station::factory()->create();

    expect($user->can('update', $station))->toBeTrue();
});

test('event managers can delete stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Event Manager');

    $station = Station::factory()->create();

    expect($user->can('delete', $station))->toBeTrue();
});

test('system administrators can view stations', function () {
    $user = User::factory()->create();
    $user->assignRole('System Administrator');

    $response = $this->actingAs($user)->get(route('stations.index'));

    $response->assertOk();
});

test('system administrators can create stations', function () {
    $user = User::factory()->create();
    $user->assignRole('System Administrator');

    expect($user->can('create', Station::class))->toBeTrue();
});

test('system administrators can edit stations', function () {
    $user = User::factory()->create();
    $user->assignRole('System Administrator');

    $station = Station::factory()->create();

    expect($user->can('update', $station))->toBeTrue();
});

test('system administrators can delete stations', function () {
    $user = User::factory()->create();
    $user->assignRole('System Administrator');

    $station = Station::factory()->create();

    expect($user->can('delete', $station))->toBeTrue();
});

test('station captains can view stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Station Captain');

    $response = $this->actingAs($user)->get(route('stations.index'));

    $response->assertOk();
});

test('station captains can create stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Station Captain');

    expect($user->can('create', Station::class))->toBeTrue();
});

test('station captains can edit stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Station Captain');

    $station = Station::factory()->create();

    expect($user->can('update', $station))->toBeTrue();
});

test('station captains can delete stations', function () {
    $user = User::factory()->create();
    $user->assignRole('Station Captain');

    $station = Station::factory()->create();

    expect($user->can('delete', $station))->toBeTrue();
});

test('users without view-stations permission cannot access stations list', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('stations.index'));

    $response->assertForbidden();
});

test('users without manage-stations permission cannot create stations', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-stations');

    $response = $this->actingAs($user)->get(route('stations.create'));

    $response->assertForbidden();
});

test('view-stations permission allows viewing but not editing', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view-stations');

    $station = Station::factory()->create();

    expect($user->can('view', $station))->toBeTrue();
    expect($user->can('update', $station))->toBeFalse();
    expect($user->can('delete', $station))->toBeFalse();
});
