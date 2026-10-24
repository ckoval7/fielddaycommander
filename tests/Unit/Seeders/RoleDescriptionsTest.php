<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Role;

uses()->group('unit', 'seeders');

test('the role seeder gives every built-in role a description', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    foreach (RoleSeeder::DESCRIPTIONS as $name => $description) {
        expect(Role::findByName($name)->description)->toBe($description);
    }
});

test('the role seeder fills a blank description on an existing role', function () {
    Role::create(['name' => 'Operator', 'guard_name' => 'web']);

    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    expect(Role::findByName('Operator')->description)->toBe(RoleSeeder::DESCRIPTIONS['Operator']);
});

test('the role seeder keeps a description an admin has edited', function () {
    Role::unguarded(fn () => Role::create(['name' => 'Operator', 'guard_name' => 'web', 'description' => 'Our own wording']));

    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    expect(Role::findByName('Operator')->description)->toBe('Our own wording');
});

test('the migration fills blank built-in descriptions and keeps edited ones', function () {
    Role::create(['name' => 'Event Manager', 'guard_name' => 'web']);
    Role::unguarded(fn () => Role::create(['name' => 'Operator', 'guard_name' => 'web', 'description' => 'Our own wording']));
    Role::create(['name' => 'Custom Role', 'guard_name' => 'web']);

    $migration = require database_path('migrations/2026_10_23_000000_add_description_to_roles_table.php');
    $migration->up();

    expect(Role::findByName('Event Manager')->description)->toBe(RoleSeeder::DESCRIPTIONS['Event Manager'])
        ->and(Role::findByName('Operator')->description)->toBe('Our own wording')
        ->and(Role::findByName('Custom Role')->description)->toBeNull();
});
