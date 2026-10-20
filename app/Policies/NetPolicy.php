<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Policies;

use App\Models\Net;
use App\Models\User;
use App\Policies\Traits\OwnerOrManagerPolicy;

class NetPolicy
{
    use OwnerOrManagerPolicy;

    /**
     * Anyone who logs net traffic can start a net.
     */
    public function create(User $user): bool
    {
        return $user->can('log-contacts');
    }

    public function update(User $user, Net $net): bool
    {
        return $this->canManageOrOwns($user, $net);
    }

    /**
     * Only a net with nothing logged on it can be deleted.
     */
    public function delete(User $user, Net $net): bool
    {
        return $this->canManageOrOwns($user, $net) && ! $net->entries()->withTrashed()->exists();
    }
}
