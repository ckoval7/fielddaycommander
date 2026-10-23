<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

test('event manager role has contact logging permissions', function () {
    $eventManager = Role::findByName('Event Manager');

    expect($eventManager->hasPermissionTo('log-contacts'))->toBeTrue()
        ->and($eventManager->hasPermissionTo('edit-contacts'))->toBeTrue();
});

test('operator role has log contacts permission', function () {
    $operator = Role::findByName('Operator');

    expect($operator->hasPermissionTo('log-contacts'))->toBeTrue()
        ->and($operator->hasPermissionTo('edit-contacts'))->toBeFalse();
});

test('station captain role has both contact permissions', function () {
    $stationCaptain = Role::findByName('Station Captain');

    expect($stationCaptain->hasPermissionTo('log-contacts'))->toBeTrue()
        ->and($stationCaptain->hasPermissionTo('edit-contacts'))->toBeTrue();
});

test('system administrator role has all permissions including contact logging', function () {
    $systemAdmin = Role::findByName('System Administrator');

    expect($systemAdmin->hasPermissionTo('log-contacts'))->toBeTrue()
        ->and($systemAdmin->hasPermissionTo('edit-contacts'))->toBeTrue();
});

test('system administrator role has manage-weather permission', function () {
    $systemAdmin = Role::findByName('System Administrator');

    expect($systemAdmin->hasPermissionTo('manage-weather'))->toBeTrue();
});

test('event manager role has manage-weather permission', function () {
    $eventManager = Role::findByName('Event Manager');

    expect($eventManager->hasPermissionTo('manage-weather'))->toBeTrue();
});

test('permission seeder refreshes descriptions of existing permissions', function () {
    Permission::where('name', 'manage-weather')->update(['description' => 'Old text']);

    $this->seed(PermissionSeeder::class);

    expect(Permission::where('name', 'manage-weather')->count())->toBe(1)
        ->and(Permission::where('name', 'manage-weather')->value('description'))->not->toBe('Old text');
});
