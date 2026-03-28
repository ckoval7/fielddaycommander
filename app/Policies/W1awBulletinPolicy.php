<?php

namespace App\Policies;

use App\Models\User;
use App\Models\W1awBulletin;
use App\Policies\Traits\OwnerOrManagerPolicy;

class W1awBulletinPolicy
{
    use OwnerOrManagerPolicy;

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
        return $this->canManageOrOwns($user, $bulletin);
    }

    public function delete(User $user, W1awBulletin $bulletin): bool
    {
        return $this->canManageOrOwns($user, $bulletin);
    }
}
