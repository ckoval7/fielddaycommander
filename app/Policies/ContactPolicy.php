<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

class ContactPolicy
{
    public function update(User $user, Contact $contact): bool
    {
        return $user->can('edit-contacts');
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $user->can('edit-contacts');
    }

    public function restore(User $user, Contact $contact): bool
    {
        return $user->can('edit-contacts');
    }
}
