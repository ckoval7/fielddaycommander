<?php

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
