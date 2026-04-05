<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckSystemSetupComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        // Allow invitation routes through regardless of setup state
        if ($request->is('register/invite/*')) {
            return $next($request);
        }

        if (config('demo.enabled')) {
            return $next($request);
        }

        // Check if setup is complete
        $setupComplete = DB::table('system_config')
            ->where('key', 'setup_completed')
            ->value('value') === 'true';

        if ($request->is('setup/*')) {
            // Block setup routes after setup is complete
            return $setupComplete ? redirect('/') : $next($request);
        }

        if (! $setupComplete) {
            return redirect('/setup/welcome');
        }

        return $next($request);
    }
}
