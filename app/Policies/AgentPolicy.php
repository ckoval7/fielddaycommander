<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

class AgentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('enroll-agents');
    }

    public function create(User $user): bool
    {
        return $user->can('enroll-agents');
    }

    /**
     * Anyone who manages external loggers can see every agent. Everyone else
     * only sees the ones they enrolled.
     */
    public function viewAll(User $user): bool
    {
        return $user->can('import-contacts');
    }

    public function revoke(User $user, Agent $agent): bool
    {
        return $this->viewAll($user)
            || ($user->can('enroll-agents') && $agent->created_by_user_id === $user->id);
    }
}
