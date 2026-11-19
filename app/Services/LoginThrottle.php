<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Per-account limit on failed sign-ins, on top of the per-email-and-IP rate
 * limit, so guessing from many machines is slowed too.
 *
 * After LOCKOUT_THRESHOLD failures in a row:
 * - progressive_delay (default): each further failure makes the account wait
 *   longer before the next try, starting at 30 seconds and doubling up to
 *   LOCKOUT_DURATION minutes.
 * - lockout: the account can't sign in for LOCKOUT_DURATION minutes. Anyone
 *   who knows the email can trigger this, so it's opt-in.
 *
 * A correct password clears the count. While an account is waiting, even a
 * correct password is refused, so the wait can't be skipped by guessing.
 */
class LoginThrottle
{
    private const int FIRST_DELAY_SECONDS = 30;

    /**
     * Seconds until the account may try again, or 0 if it may try now.
     */
    public function secondsUntilAvailable(User $user): int
    {
        $availableAt = Cache::get($this->key($user));

        if (! is_int($availableAt)) {
            return 0;
        }

        return max(0, $availableAt - now()->getTimestamp());
    }

    /**
     * Count a wrong password and start a wait once the threshold is reached.
     */
    public function recordFailure(User $user): void
    {
        $user->increment('failed_login_attempts');
        $over = $user->failed_login_attempts - $this->threshold();

        if ($over < 0) {
            return;
        }

        if (config('auth-security.failed_login_strategy') === 'lockout') {
            // A fresh set of tries once the lockout ends.
            $seconds = $this->maxDelaySeconds();
            $user->forceFill(['failed_login_attempts' => 0])->save();
        } else {
            $seconds = min(self::FIRST_DELAY_SECONDS * (2 ** min($over, 20)), $this->maxDelaySeconds());
        }

        Cache::put($this->key($user), now()->getTimestamp() + $seconds, $seconds);
    }

    /**
     * Clear the count after a successful sign-in or an administrator unlock.
     */
    public function clear(User $user): void
    {
        Cache::forget($this->key($user));

        if ($user->failed_login_attempts !== 0) {
            $user->forceFill(['failed_login_attempts' => 0])->save();
        }
    }

    private function threshold(): int
    {
        return max(1, (int) config('auth-security.lockout_threshold'));
    }

    private function maxDelaySeconds(): int
    {
        return max(1, (int) config('auth-security.lockout_duration')) * 60;
    }

    private function key(User $user): string
    {
        return "login-throttle:{$user->id}";
    }
}
