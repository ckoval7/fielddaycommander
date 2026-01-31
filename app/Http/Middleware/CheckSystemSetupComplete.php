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
        // Allow setup routes through
        if ($request->is('setup/*')) {
            return $next($request);
        }

        // Check if setup is complete
        $setupComplete = DB::table('system_config')
            ->where('key', 'setup_completed')
            ->value('value') === 'true';

        if (! $setupComplete) {
            // Redirect to setup wizard
            if (! $request->is('setup/*')) {
                return redirect('/setup/welcome');
            }
        }

        return $next($request);
    }
}
