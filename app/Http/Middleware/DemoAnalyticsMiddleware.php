<?php

namespace App\Http\Middleware;

use App\Models\DemoEvent;
use App\Models\DemoSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DemoAnalyticsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled')) {
            return $next($request);
        }

        // Use path matching instead of routeIs() because this middleware runs
        // before SubstituteBindings in the priority list, so route names are not yet available.
        if ($request->is('demo', 'demo/provision', 'demo/reset', 'demo/analytics/beacon',
            'demo/analytics', 'demo/analytics/api')) {
            return $next($request);
        }

        $cookie = $request->cookie('demo_session');
        [$uuid] = array_pad(explode('|', $cookie ?? '', 2), 2, null);

        if (! $uuid || ! Str::isUuid($uuid)) {
            return $next($request);
        }

        $session = DemoSession::where('session_uuid', $uuid)->first();

        if (! $session) {
            return $next($request);
        }

        $session->increment('total_page_views', 1, ['last_seen_at' => now()]);

        DemoEvent::create([
            'demo_session_id' => $session->id,
            'type' => 'page_view',
            'name' => $request->route()?->getName() ?? $request->path(),
            'route_name' => $request->route()?->getName(),
            'metadata' => [
                'path' => '/'.ltrim($request->path(), '/'),
                'method' => $request->method(),
            ],
        ]);

        return $next($request);
    }
}
