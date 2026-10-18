<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Headers applied to every web response unless the response already sets them.
     *
     * No page is meant to be embedded by another site, so framing is limited
     * to the app's own origin. A full CSP is deliberately omitted because
     * Livewire and Alpine need unsafe-eval. HSTS is opt-in through
     * SECURITY_HSTS_MAX_AGE, because a cached HSTS policy locks browsers out
     * of self-signed certificates and plain-HTTP access to the same host.
     *
     * @var array<string, string>
     */
    private const array HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "frame-ancestors 'self'",
    ];

    /**
     * Add baseline security headers to the response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $hstsMaxAge = (int) config('auth-security.hsts_max_age', 0);

        if ($hstsMaxAge > 0 && $request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', "max-age={$hstsMaxAge}");
        }

        return $response;
    }
}
