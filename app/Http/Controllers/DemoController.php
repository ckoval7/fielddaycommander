<?php

namespace App\Http\Controllers;

use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoController extends Controller
{
    public function landing(): View
    {
        abort_unless(config('demo.enabled'), 404);

        return view('demo.landing');
    }

    public function provision(Request $request): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $request->validate([
            'role' => 'required|in:operator,station_captain,event_manager,system_admin',
        ]);

        $currentCount = $this->countDemoSessions();

        if ($currentCount >= config('demo.max_sessions', 25)) {
            return redirect()->route('demo.landing')
                ->with('error', 'Demo capacity is currently full. Please try again in a few minutes.');
        }

        $uuid = (string) Str::uuid();
        $dbName = $this->safeDemoDbName($uuid);

        DB::statement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        Config::set('database.connections.demo.database', $dbName);
        DB::purge('demo');

        Artisan::call('migrate', [
            '--database' => 'demo',
            '--force' => true,
            '--no-interaction' => true,
        ]);

        Artisan::call('db:seed', [
            '--class' => DemoSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->resolveUserForRole($request->role);
        Auth::login($user);

        session(['dev_role_override' => $user->roles->first()?->name ?? '']);

        DemoSession::create([
            'session_uuid' => $uuid,
            'role' => $request->role,
            'visitor_hash' => DemoSession::visitorHash($request->ip()),
            'user_agent' => $request->userAgent() ?? '',
            'device_type' => DemoSession::parseDeviceType($request->userAgent() ?? ''),
            'referrer' => $request->headers->get('referer'),
            'provisioned_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addHours(config('demo.ttl_hours', 24)),
        ]);

        $cookie = cookie(
            'demo_session',
            $uuid.'|'.$request->role,
            config('demo.ttl_hours', 24) * 60,
            '/',
            null,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'Lax'
        );

        return redirect('/')->withCookie($cookie);
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $cookie = $request->cookie('demo_session');
        [$uuid] = array_pad(explode('|', $cookie ?? '', 2), 2, null);

        try {
            if ($uuid && Str::isUuid($uuid)) {
                $demoSession = DemoSession::where('session_uuid', $uuid)->first();
                if ($demoSession) {
                    DemoEvent::create([
                        'demo_session_id' => $demoSession->id,
                        'type' => 'action',
                        'name' => 'session.reset',
                        'metadata' => ['role' => $demoSession->role],
                    ]);

                    $demoSession->update([
                        'was_reset' => true,
                        'last_seen_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable) {
            // Analytics tracking is best-effort; don't block the reset.
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('demo.landing')
            ->withoutCookie('demo_session');
    }

    private function countDemoSessions(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            return 0;
        }

        $rows = DB::select(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'demo\_%'"
        );

        return count(array_filter($rows, function ($row): bool {
            $name = $row->schema_name ?? $row->SCHEMA_NAME;

            return (bool) preg_match('/^demo_[a-f0-9_]{32,40}$/', $name);
        }));
    }

    private function safeDemoDbName(string $uuid): string
    {
        $dbName = 'demo_'.str_replace('-', '_', $uuid);

        abort_unless(preg_match('/^demo_[a-f0-9_]{32,40}$/', $dbName), 400, 'Invalid demo session identifier.');

        return $dbName;
    }

    private function resolveUserForRole(string $role): User
    {
        $roleMap = [
            'system_admin' => 'System Administrator',
            'event_manager' => 'Event Manager',
            'station_captain' => 'Station Captain',
            'operator' => 'Operator',
        ];

        $spatieRoleName = $roleMap[$role] ?? 'Operator';

        return User::role($spatieRoleName)->firstOrFail();
    }
}
