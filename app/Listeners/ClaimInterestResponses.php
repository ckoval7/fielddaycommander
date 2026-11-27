<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Listeners;

use App\Models\EventInterest;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Ties interest-form responses sent under an email to the account that
 * owns it, once that account is approved and counts as owning the email.
 * Runs on registration, email verification and every way an account is
 * unlocked; until then, the response stays editable from the public form.
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
        if ($user->account_locked_at !== null || ! $user->hasVerifiedEmail()) {
            return;
        }

        EventInterest::whereNull('user_id')
            ->where('email', mb_strtolower($user->email))
            ->update(['user_id' => $user->id]);
    }
}
