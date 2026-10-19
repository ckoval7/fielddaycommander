<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Policies;

use App\Models\NetLogEntry;
use App\Models\User;
use App\Policies\Traits\OwnerOrManagerPolicy;

class NetLogEntryPolicy
{
    use OwnerOrManagerPolicy;

    public function create(User $user): bool
    {
        return $user->can('log-contacts');
    }

    public function update(User $user, NetLogEntry $entry): bool
    {
        return $this->canManageOrOwns($user, $entry);
    }

    public function delete(User $user, NetLogEntry $entry): bool
    {
        return $this->canManageOrOwns($user, $entry);
    }
}
