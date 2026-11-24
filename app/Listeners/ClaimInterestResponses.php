<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Listeners;

use App\Models\EventInterest;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Ties interest-form responses sent under an email to the account that
 * owns it, once the account counts as owning the email.
 */
class ClaimInterestResponses
{
    public function handle(Verified $event): void
    {
        if ($event->user instanceof User) {
            self::claimFor($event->user);
        }
    }

    public static function claimFor(User $user): void
    {
        EventInterest::whereNull('user_id')
            ->where('email', mb_strtolower($user->email))
            ->update(['user_id' => $user->id]);
    }
}
