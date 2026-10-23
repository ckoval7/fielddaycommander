<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses()->group('unit', 'seeders');

function runRemoveManageEquipmentMigration(): void
{
    $migration = require database_path('migrations/2026_10_23_000002_remove_manage_equipment_permission.php');
    $migration->up();
}

test('the upgrade deletes manage-equipment and detaches it from roles and users', function () {
    Permission::create(['name' => 'manage-equipment', 'guard_name' => 'web']);
    Permission::create(['name' => 'manage-own-equipment', 'guard_name' => 'web']);

    $role = Role::create(['name' => 'Club Equipment', 'guard_name' => 'web']);
    $role->givePermissionTo(['manage-equipment', 'manage-own-equipment']);
    $user = User::factory()->create();
    $user->givePermissionTo('manage-equipment');

    runRemoveManageEquipmentMigration();

    expect(Permission::where('name', 'manage-equipment')->exists())->toBeFalse()
        ->and(DB::table('role_has_permissions')->count())->toBe(1)
        ->and(DB::table('model_has_permissions')->count())->toBe(0)
        ->and($role->fresh()->hasPermissionTo('manage-own-equipment'))->toBeTrue();
});

test('the upgrade does nothing when manage-equipment is already gone', function () {
    Permission::create(['name' => 'manage-own-equipment', 'guard_name' => 'web']);

    runRemoveManageEquipmentMigration();

    expect(Permission::where('name', 'manage-own-equipment')->exists())->toBeTrue();
});

test('a fresh install does not create manage-equipment', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    expect(Permission::where('name', 'manage-equipment')->exists())->toBeFalse()
        ->and(Role::findByName('Station Captain')->hasPermissionTo('manage-own-equipment'))->toBeTrue();
});
