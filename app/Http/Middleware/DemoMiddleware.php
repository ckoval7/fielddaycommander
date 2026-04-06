<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class DemoMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Validates the demo_session cookie and switches the 'demo' DB connection
     * to the visitor's isolated database on every request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled')) {
            return $next($request);
        }

        // Allow demo landing and provision routes through without a session
        if ($request->routeIs('demo.landing', 'demo.provision')) {
            return $next($request);
        }

        $cookie = $request->cookie('demo_session');

        // Cookie format: "uuid|role_slug" (e.g. "abc-123|system_admin")
        [$uuid, $roleSlug] = array_pad(explode('|', $cookie ?? '', 2), 2, null);

        // Missing or malformed cookie → redirect to landing
        if (! $uuid || ! Str::isUuid($uuid)) {
            return redirect()->route('demo.landing');
        }

        $dbName = 'demo_'.str_replace('-', '_', $uuid);

        if (! $this->demoDatabaseExists($dbName)) {
            return redirect()->route('demo.landing')
                ->withoutCookie('demo_session');
        }

        // Switch the demo connection to this visitor's isolated database
        Config::set('database.connections.demo.database', $dbName);
        DB::purge('demo');

        // Clear Spatie permission cache so it re-loads from the demo DB
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Ensure the visitor stays authenticated after DB swap.
        // The session may lose auth state due to config caching, Octane, or
        // driver mismatches, so we re-authenticate from the cookie's role slug.
        if (Auth::guest() && $roleSlug) {
            $user = User::role($this->resolveRoleName($roleSlug))->first();
            if ($user) {
                Auth::login($user);
                session(['dev_role_override' => $user->roles->first()?->name ?? '']);
            }
        }

        return $next($request);
    }

    /**
     * Map a cookie role slug to a Spatie role name.
     */
    protected function resolveRoleName(string $slug): string
    {
        return match ($slug) {
            'system_admin' => 'System Administrator',
            'event_manager' => 'Event Manager',
            'station_captain' => 'Station Captain',
            default => 'Operator',
        };
    }

    /**
     * Check whether the demo database exists.
     *
     * Uses information_schema on MySQL/MariaDB. Falls back gracefully for other
     * drivers (e.g. SQLite in the test environment) by returning false.
     */
    protected function demoDatabaseExists(string $dbName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $results = DB::select(
                'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?',
                [$dbName]
            );

            return ! empty($results);
        }

        return false;
    }
}
