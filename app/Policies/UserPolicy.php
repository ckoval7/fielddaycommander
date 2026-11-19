<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * User administration never hands out more than the administrator holds.
 *
 * With manage-users alone, you can grant a role or act on an account only
 * when you already hold every permission involved, so a custom role given
 * "Manage Users" can't promote anyone (itself included) to System
 * Administrator or take over a more privileged account by resetting its
 * password. Holders of manage-roles are exempt: they can already give their
 * own role any permission, and the Config Only setup account needs this to
 * create the first System Administrator.
 */
class UserPolicy
{
    /**
     * Edit an account's details and role, or unlock it.
     */
    public function update(User $actor, User $target): bool
    {
        return $actor->can('manage-users')
            && $this->holdsAll($actor, $target->getAllPermissions()->pluck('name'));
    }

    /**
     * Lock, delete, or reset the password of an account. The SYSTEM setup
     * account is never touched this way.
     */
    public function manageAccount(User $actor, User $target): bool
    {
        return ! $target->isSystemUser() && $this->update($actor, $target);
    }

    /**
     * Give a role to an account (registered as the "assign-role" gate).
     */
    public function assignRole(User $actor, Role $role): bool
    {
        return $actor->can('manage-users')
            && $this->holdsAll($actor, $role->permissions->pluck('name'));
    }

    /**
     * @param  Collection<int, string>  $permissions
     */
    private function holdsAll(User $actor, Collection $permissions): bool
    {
        if ($actor->can('manage-roles')) {
            return true;
        }

        return $permissions->diff($actor->getAllPermissions()->pluck('name'))->isEmpty();
    }
}
