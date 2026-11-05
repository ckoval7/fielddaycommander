<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses agent API requests over plain HTTP, since they carry enrollment
 * codes and tokens. A request through a TLS proxy counts as secure only when
 * the proxy is in TRUSTED_PROXIES.
 */
class RequireAgentHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->secure()) {
            return response()->json([
                'code' => 'https_required',
                'message' => 'Connect over HTTPS. Behind a TLS proxy, the proxy must be in TRUSTED_PROXIES.',
            ], 403);
        }

        return $next($request);
    }
}
