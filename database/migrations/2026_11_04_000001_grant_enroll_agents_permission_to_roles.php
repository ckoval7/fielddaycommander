<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const ROLES = ['System Administrator', 'Event Manager', 'Station Captain', 'Operator'];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(
            ['name' => 'enroll-agents', 'guard_name' => 'web'],
            ['description' => 'Create Field Day Commander Agent enrollment codes and revoke your agents'],
        );

        Role::whereIn('name', self::ROLES)->where('guard_name', 'web')->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'enroll-agents')->where('guard_name', 'web')->delete();
    }
};
