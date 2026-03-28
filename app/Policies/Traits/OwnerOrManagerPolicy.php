<?php

namespace App\Policies\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait OwnerOrManagerPolicy
{
    protected function canManageOrOwns(User $user, Model $model): bool
    {
        if ($user->can('manage-bonuses')) {
            return true;
        }

        return $user->can('log-contacts') && $model->user_id === $user->id;
    }
}
