<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Policies;

use App\Models\Image;
use App\Models\User;

class ImagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Image $image): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Image $image): bool
    {
        if ($user->can('manage-images')) {
            return true;
        }

        return $user->id === $image->uploaded_by_user_id;
    }
}
