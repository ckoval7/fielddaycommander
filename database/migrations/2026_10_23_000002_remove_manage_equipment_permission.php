<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * manage-equipment was the original catch-all equipment permission. The
 * narrower equipment permissions replaced it and nothing checks it any more.
 * Upgrades don't re-run the seeders, so it is removed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::where('name', 'manage-equipment')
            ->where('guard_name', 'web')
            ->get()
            ->each(function (Permission $permission): void {
                // Delete detaches it from every role and user.
                $permission->delete();
            });

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Nothing to restore: the permission did nothing, and which roles and
     * users held it is not recorded once it is deleted.
     */
    public function down(): void
    {
        //
    }
};
