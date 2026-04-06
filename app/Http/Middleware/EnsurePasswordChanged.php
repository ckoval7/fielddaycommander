<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Redirect users who must change their password before accessing the app.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('demo.enabled')) {
            return $next($request);
        }

        if (
            $request->user()
            && $request->user()->requires_password_change
            && ! $request->routeIs('profile')
            && ! $request->routeIs('logout')
            && ! $request->ajax()
            && ! $request->wantsJson()
            && $request->isMethod('GET')
        ) {
            return redirect()->route('profile', ['tab' => 'security']);
        }

        return $next($request);
    }
}
