<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether a request's connection is safe for relay enrollment codes and
 * tokens: HTTPS, or plain HTTP that never leaves the server's own machine.
 */
class RelayConnection
{
    /**
     * @var list<string>
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * @var list<string>
     */
    private const LOOPBACK_RANGES = ['127.0.0.0/8', '::1/128'];

    public function isAllowed(Request $request): bool
    {
        return $request->secure() || $this->isLocalhost($request);
    }

    /**
     * The Host header can say anything, so the sender's address decides this.
     * A reverse proxy on the same machine also connects from loopback, so a
     * forwarded request doesn't count, whatever its Host says.
     */
    private function isLocalhost(Request $request): bool
    {
        return in_array(strtolower($request->getHost()), self::LOOPBACK_HOSTS, true)
            && IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), self::LOOPBACK_RANGES)
            && ! $request->headers->has('X-Forwarded-For');
    }
}
