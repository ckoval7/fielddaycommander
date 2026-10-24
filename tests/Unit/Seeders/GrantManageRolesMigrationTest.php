<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses()->group('unit', 'seeders');

function runGrantManageRolesMigration(): void
{
    $migration = require database_path('migrations/2026_10_23_000001_grant_manage_roles_to_settings_managers.php');
    $migration->up();
}

test('every role that could manage settings keeps role management after upgrade', function () {
    Permission::create(['name' => 'manage-settings', 'guard_name' => 'web']);
    Permission::create(['name' => 'log-contacts', 'guard_name' => 'web']);

    $settingsRole = Role::create(['name' => 'Club Admin', 'guard_name' => 'web']);
    $settingsRole->givePermissionTo('manage-settings');
    $systemAdmin = Role::create(['name' => 'System Administrator', 'guard_name' => 'web']);
    $systemAdmin->givePermissionTo('log-contacts');
    $operator = Role::create(['name' => 'Operator', 'guard_name' => 'web']);
    $operator->givePermissionTo('log-contacts');

    runGrantManageRolesMigration();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($settingsRole->fresh()->hasPermissionTo('manage-roles'))->toBeTrue()
        ->and($systemAdmin->fresh()->hasPermissionTo('manage-roles'))->toBeTrue()
        ->and($operator->fresh()->hasPermissionTo('manage-roles'))->toBeFalse();
});

test('the upgrade updates the manage-roles and manage-settings descriptions', function () {
    DB::table('permissions')->insert([
        ['name' => 'manage-roles', 'guard_name' => 'web', 'description' => 'Not used. Role management requires manage-settings.'],
        ['name' => 'manage-settings', 'guard_name' => 'web', 'description' => 'Change system preferences, site branding, roles, and permissions'],
    ]);

    runGrantManageRolesMigration();

    expect(Permission::findByName('manage-roles')->description)->toBe('Create and delete roles and choose their permissions')
        ->and(Permission::findByName('manage-settings')->description)->toBe('Change system preferences and site branding');
});

test('a fresh install gives manage-roles to System Administrator and Config Only', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    expect(Role::findByName('System Administrator')->hasPermissionTo('manage-roles'))->toBeTrue()
        ->and(Role::findByName('Config Only')->hasPermissionTo('manage-roles'))->toBeTrue()
        ->and(Role::findByName('Event Manager')->hasPermissionTo('manage-roles'))->toBeFalse();
});
