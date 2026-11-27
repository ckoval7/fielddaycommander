<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\User;
use App\Support\SignInLinks;
use Illuminate\Console\Command;

/**
 * Prints a one-time sign-in link for an account, SYSTEM by default.
 *
 * It's the way back in when SYSTEM is locked (SYSTEM_ACCOUNT_LOCKED), or
 * when an account's password or 2FA device is lost and there's no mail on
 * the field LAN to reset it. Anyone who can run artisan on the server
 * already controls the install, so it asks for nothing more.
 */
class SignInLinkCommand extends Command
{
    protected $signature = 'user:sign-in-link
        {callsign=SYSTEM : Callsign of the account to sign in to}
        {--minutes= : Link lifetime in minutes (default and maximum: SIGN_IN_LINK_MAX_MINUTES)}
        {--url= : Address where you will open the link, if different from APP_URL}
        {--by=the server command line : Name to record in the audit log}';

    protected $description = 'Print a one-time sign-in link for an account';

    public function handle(): int
    {
        $callsign = mb_strtoupper(trim((string) $this->argument('callsign')));
        $by = trim((string) $this->option('by'));

        if (! User::where('call_sign', $callsign)->exists()) {
            $this->error("No account found with callsign {$callsign}.");

            return self::FAILURE;
        }

        if ($by === '' || mb_strlen($by) > SignInLinks::MAX_BY_LENGTH) {
            $this->error('--by must be 1 to '.SignInLinks::MAX_BY_LENGTH.' characters.');

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $host = parse_url($base, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $this->error("{$base} is not a full address. Use --url with a full address, such as --url=http://192.168.1.20");

            return self::FAILURE;
        }

        $minutes = min(SignInLinks::maxMinutes(), max(1, (int) ($this->option('minutes') ?: SignInLinks::maxMinutes())));
        $expires = now()->addMinutes($minutes)->getTimestamp();
        $nonce = bin2hex(random_bytes(16));

        $query = http_build_query([
            'user' => $callsign,
            'by' => $by,
            'expires' => $expires,
            'nonce' => $nonce,
            'signature' => SignInLinks::sign(SignInLinks::localKey(), $host, $callsign, $by, $expires, $nonce),
        ]);

        $this->info("Sign in as {$callsign} with this link within {$minutes} minutes. It works once.");
        $this->line($base.'/sign-in/link?'.$query);

        return self::SUCCESS;
    }
}
