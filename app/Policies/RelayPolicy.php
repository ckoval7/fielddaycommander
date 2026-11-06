<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Policies;

use App\Models\Relay;
use App\Models\User;

class RelayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('enroll-relays');
    }

    public function create(User $user): bool
    {
        return $user->can('enroll-relays');
    }

    /**
     * Anyone who manages external loggers can see every relay. Everyone else
     * only sees the ones they enrolled.
     */
    public function viewAll(User $user): bool
    {
        return $user->can('import-contacts');
    }

    public function revoke(User $user, Relay $relay): bool
    {
        return $this->viewAll($user)
            || ($user->can('enroll-relays') && $relay->created_by_user_id === $user->id);
    }
}
