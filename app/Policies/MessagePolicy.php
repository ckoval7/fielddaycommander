<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Policies;

use App\Models\Message;
use App\Models\User;
use App\Policies\Traits\OwnerOrManagerPolicy;

class MessagePolicy
{
    use OwnerOrManagerPolicy;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Message $message): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('log-contacts');
    }

    public function update(User $user, Message $message): bool
    {
        return $this->canManageOrOwns($user, $message);
    }

    public function delete(User $user, Message $message): bool
    {
        return $this->canManageOrOwns($user, $message);
    }
}
