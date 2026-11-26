<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\HostedMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Signs the hosting service's support staff in to a hosted club as SYSTEM.
 *
 * The hosting service builds the link and signs it with the club's
 * HOSTED_SUPPORT_KEY:
 *
 *     /support/sign-in?expires=<unix time>&nonce=<random>&staff=<name>&signature=<hex>
 *
 * where signature = HMAC-SHA256(key, "fd-commander-support-sign-in\n<host>\n<expires>\n<nonce>\n<staff>").
 * Binding the host keeps a link for one club from opening another, the
 * expiry is capped by hosted.support_link_max_minutes, and each nonce
 * works once. Every sign-in is written to the club's audit log.
 */
class SupportSignInController extends Controller
{
    public const string SIGNATURE_CONTEXT = 'fd-commander-support-sign-in';

    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(HostedMode::allowsSupportSignIn(), 404);

        $expires = (int) $request->query('expires');
        $nonce = (string) $request->query('nonce');
        $staff = trim((string) $request->query('staff'));
        $signature = (string) $request->query('signature');

        $maxExpiry = now()->addMinutes((int) config('hosted.support_link_max_minutes'))->getTimestamp();

        abort_if(
            $expires < now()->getTimestamp()
            || $expires > $maxExpiry
            || strlen($nonce) < 16
            || $staff === ''
            || mb_strlen($staff) > 100,
            403,
        );

        $expected = self::sign($request->getHost(), $expires, $nonce, $staff);

        abort_unless(hash_equals($expected, $signature), 403);

        // Cache::add() only stores a key that isn't there yet, so a link
        // opened twice fails the second time.
        abort_unless(Cache::add('hosted.support-nonce:'.hash('sha256', $nonce), true, $expires - now()->getTimestamp() + 60), 403);

        $system = User::where('call_sign', User::SYSTEM_CALL_SIGN)->firstOrFail();

        if (Auth::check()) {
            Auth::logout();
        }

        $request->session()->invalidate();
        Auth::login($system);
        $request->session()->regenerate();
        $request->session()->put(HostedMode::SUPPORT_SESSION_KEY, $staff);

        AuditLog::log('support.sign_in', userId: $system->id, newValues: ['staff' => $staff], isCritical: true);

        return redirect()->route('dashboard');
    }

    /**
     * Build the signature the hosting service puts on a support link.
     */
    public static function sign(string $host, int $expires, string $nonce, string $staff): string
    {
        $payload = implode("\n", [self::SIGNATURE_CONTEXT, strtolower($host), $expires, $nonce, $staff]);

        return hash_hmac('sha256', $payload, (string) config('hosted.support_key'));
    }
}
