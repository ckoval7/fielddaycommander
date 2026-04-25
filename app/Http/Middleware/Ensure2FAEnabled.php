<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Ensure2FAEnabled
{
    /**
     * Enforce the 2FA_MODE configuration:
     * - "required": redirect users without confirmed 2FA to profile setup,
     *   and block Fortify's raw disable endpoint.
     * - "disabled": block Fortify's raw enable endpoint.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $mode = config('auth-security.2fa_mode');

        // Block Fortify's raw disable endpoint when 2FA is required
        if ($mode === 'required' && $request->routeIs('two-factor.disable')) {
            abort(403, 'Two-factor authentication is required and cannot be disabled.');
        }

        // Block Fortify's raw enable endpoint when 2FA is disabled
        if ($mode === 'disabled' && $request->routeIs('two-factor.enable')) {
            abort(403, 'Two-factor authentication is disabled.');
        }

        // Redirect users without confirmed 2FA when mode is required
        if (
            $mode === 'required'
            && $request->user()
            && ! $request->user()->two_factor_confirmed_at
            && ! $request->routeIs('profile')
            && ! $request->routeIs('logout')
            && ! $request->routeIs('logout.get')
            && ! $request->routeIs('two-factor.*')
            && ! $request->routeIs('livewire.*')
            && $request->isMethod('GET')
            && ! $request->ajax()
            && ! $request->wantsJson()
        ) {
            return redirect()->route('profile', ['tab' => 'security']);
        }

        return $next($request);
    }
}
