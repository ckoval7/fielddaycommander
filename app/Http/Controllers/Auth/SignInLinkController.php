<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SignInLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Opens a one-time sign-in link (see SignInLinks for the format).
 */
class SignInLinkController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = mb_strtoupper(trim((string) $request->query('user')));
        $by = trim((string) $request->query('by'));
        $expires = (int) $request->query('expires');
        $nonce = (string) $request->query('nonce');
        $signature = (string) $request->query('signature');

        abort_if(
            $user === ''
            || $by === ''
            || mb_strlen($by) > SignInLinks::MAX_BY_LENGTH
            || strlen($nonce) < SignInLinks::MIN_NONCE_LENGTH
            || $expires < now()->getTimestamp()
            || $expires > now()->addMinutes(SignInLinks::maxMinutes())->getTimestamp(),
            403,
        );

        abort_unless(SignInLinks::verify($signature, $request->getHost(), $user, $by, $expires, $nonce), 403);

        // Cache::add() only stores a key that isn't there yet, so a link
        // opened twice fails the second time.
        abort_unless(Cache::add('sign-in-link-nonce:'.hash('sha256', $nonce), true, $expires - now()->getTimestamp() + 60), 403);

        $account = User::where('call_sign', $user)->first();

        // An administrator's lock on an account outranks a sign-in link.
        abort_if($account === null || ($account->isLocked() && ! $account->isSystemUser()), 403);

        if (Auth::check()) {
            Auth::logout();
        }

        $request->session()->invalidate();
        Auth::login($account);
        $request->session()->regenerate();
        $request->session()->put(SignInLinks::SESSION_KEY, $by);

        AuditLog::log('user.login.sign_in_link', userId: $account->id, newValues: ['by' => $by], isCritical: true);

        return redirect()->route('dashboard');
    }
}
