<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce the configured registration mode by blocking access to
 * self-registration routes when registration is disabled.
 */
class EnforceRegistrationMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('register') && config('auth-security.registration_mode') === 'disabled') {
            abort(404);
        }

        return $next($request);
    }
}
