<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
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
        if ($user->can('manage-bonuses')) {
            return true;
        }

        return $user->can('log-contacts') && $message->user_id === $user->id;
    }

    public function delete(User $user, Message $message): bool
    {
        if ($user->can('manage-bonuses')) {
            return true;
        }

        return $user->can('log-contacts') && $message->user_id === $user->id;
    }
}
