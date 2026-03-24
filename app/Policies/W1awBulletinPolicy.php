<?php

namespace App\Policies;

use App\Models\User;
use App\Models\W1awBulletin;

class W1awBulletinPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, W1awBulletin $bulletin): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('log-contacts');
    }

    public function update(User $user, W1awBulletin $bulletin): bool
    {
        if ($user->can('manage-bonuses')) {
            return true;
        }

        return $user->can('log-contacts') && $bulletin->user_id === $user->id;
    }

    public function delete(User $user, W1awBulletin $bulletin): bool
    {
        if ($user->can('manage-bonuses')) {
            return true;
        }

        return $user->can('log-contacts') && $bulletin->user_id === $user->id;
    }
}
