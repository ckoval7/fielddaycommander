<?php

namespace App\Http\Controllers;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoController extends Controller
{
    public function landing(): \Illuminate\Contracts\View\View
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
        $dbName = 'demo_'.str_replace('-', '_', $uuid);

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

        $cookie = cookie(
            'demo_session',
            $uuid,
            config('demo.ttl_hours', 24) * 60,
            '/',
            null,
            secure: false,
            httpOnly: true,
            sameSite: 'Lax'
        );

        return redirect('/')->withCookie($cookie);
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $uuid = $request->cookie('demo_session');

        if (! $uuid || ! Str::isUuid($uuid)) {
            return redirect()->route('demo.landing');
        }

        $dbName = 'demo_'.str_replace('-', '_', $uuid);
        $role = $request->input('role', 'event_manager');

        Config::set('database.connections.demo.database', $dbName);
        DB::purge('demo');

        Artisan::call('db:wipe', [
            '--database' => 'demo',
            '--force' => true,
            '--no-interaction' => true,
        ]);

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

        $user = $this->resolveUserForRole($role);
        Auth::login($user);

        session(['dev_role_override' => $user->roles->first()?->name ?? '']);

        return redirect('/');
    }

    private function countDemoSessions(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            return 0;
        }

        return count(DB::select(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'demo\_%'"
        ));
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
