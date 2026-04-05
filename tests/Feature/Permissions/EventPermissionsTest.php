<?php

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Seed permissions and roles for testing
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\PermissionSeeder']);
    $this->artisan('db:seed', ['--class' => 'Database\\Seeders\\RoleSeeder']);
});

test('event management permissions exist', function () {
    $permissions = [
        'view-events',
        'create-events',
        'edit-events',
        'delete-events',
        'manage-bulletins',
    ];

    foreach ($permissions as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
    }
});

test('system administrator has all event permissions', function () {
    $admin = Role::where('name', 'System Administrator')->first();

    expect($admin->hasPermissionTo('view-events'))->toBeTrue();
    expect($admin->hasPermissionTo('create-events'))->toBeTrue();
    expect($admin->hasPermissionTo('edit-events'))->toBeTrue();
    expect($admin->hasPermissionTo('delete-events'))->toBeTrue();
    expect($admin->hasPermissionTo('manage-bulletins'))->toBeTrue();
});

test('event manager role exists with all event permissions', function () {
    $eventManager = Role::where('name', 'Event Manager')->first();

    expect($eventManager)->not->toBeNull();
    expect($eventManager->hasPermissionTo('view-events'))->toBeTrue();
    expect($eventManager->hasPermissionTo('create-events'))->toBeTrue();
    expect($eventManager->hasPermissionTo('edit-events'))->toBeTrue();
    expect($eventManager->hasPermissionTo('delete-events'))->toBeTrue();
    expect($eventManager->hasPermissionTo('manage-bulletins'))->toBeTrue();
});
