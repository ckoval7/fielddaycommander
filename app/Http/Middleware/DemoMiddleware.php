<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\DemoCookie;
use App\Support\DemoDatabase;
use App\Support\StationMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DemoMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Validates the demo_session cookie and switches the 'demo' DB connection
     * to the visitor's isolated database on every request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled')) {
            return $next($request);
        }

        // Allow demo infrastructure routes through without demo DB switching.
        if (self::isInfrastructureRequest($request)) {
            return $next($request);
        }

        $visitor = DemoCookie::fromRequest($request);

        // Missing or malformed cookie → redirect to landing
        if ($visitor === null) {
            return redirect()->route('demo.landing');
        }

        $dbName = 'demo_'.str_replace('-', '_', $visitor->uuid);

        // Under Octane, `config()` mutations persist across requests in the same
        // worker. If this worker is already pointed at the visitor's DB from a
        // prior request, skip the expensive swap work (PDO reconnect, permission
        // cache flush, user reload) — the swapped connection, permission cache,
        // and session state all remain correct for this visitor.
        if (config('database.connections.demo.database') === $dbName) {
            // A browser whose station mode was revoked elsewhere was left signed out.
            if (! Auth::check() && $this->hasRevokedStationModeCookie($request)) {
                $this->signInVisitor($visitor->userId);
            }

            return $next($request);
        }

        if (! $this->demoDatabaseExists($dbName)) {
            return redirect()->route('demo.landing')
                ->withoutCookie(DemoCookie::NAME);
        }

        // Switch the demo connection to this visitor's isolated database
        DemoDatabase::activate($dbName);

        // On visitor change, re-authenticate so the session guard doesn't serve
        // a stale user resolved against the previous visitor's demo DB.
        if (! $this->isStationModeBrowser($request)) {
            $this->signInVisitor($visitor->userId);
        }

        return $next($request);
    }

    /**
     * Whether the request is for a demo infrastructure route. These operate on
     * the main database (landing, provisioning, analytics), so the visitor's
     * database is not switched in and their users cannot be resolved.
     *
     * Uses path matching instead of routeIs() because DemoMiddleware runs before
     * SubstituteBindings in the priority list, so route names are not yet available.
     */
    public static function isInfrastructureRequest(Request $request): bool
    {
        return config('demo.enabled') && $request->is(
            'demo', 'demo/provision', 'demo/reset', 'demo/join/*', 'demo/analytics/beacon',
            'demo/analytics', 'demo/analytics/api', 'demo/analytics/sessions/*/events',
        );
    }

    /**
     * A station-mode browser belongs to whoever is at the station: the walk-up
     * operator in the session, or nobody while it waits for one. Signing the
     * visitor in there would log contacts under the wrong call.
     */
    protected function isStationModeBrowser(Request $request): bool
    {
        return $request->session()->has(StationMode::SESSION_KEY)
            || StationMode::deviceFromCookie($request) !== null;
    }

    /**
     * Whether this browser still holds a station-mode cookie for a device that was revoked.
     */
    protected function hasRevokedStationModeCookie(Request $request): bool
    {
        return filled($request->cookie(StationMode::COOKIE))
            && ! $this->isStationModeBrowser($request);
    }

    /**
     * Sign the visitor in as the account their cookie names, with its role override.
     */
    protected function signInVisitor(int $userId): void
    {
        $user = User::find($userId);

        if ($user) {
            Auth::setUser($user);
            session(['dev_role_override' => $user->roles->first()?->name ?? '']);
        }
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
