<?php

namespace App\Http\Controllers;

use App\Console\Commands\DemoSimulateActivity;
use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Models\User;
use App\Services\DemoDatabasePool;
use App\Support\DemoDatabase;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class DemoController extends Controller
{
    /**
     * How many times the landing page resubmits a visitor who found every
     * provisioning slot busy before giving up and asking them to come back.
     */
    private const MAX_PROVISION_ATTEMPTS = 10;

    /**
     * Personas a visitor can sign in as, keyed by the slug stored in the
     * demo_session cookie.
     *
     * @var array<string, string>
     */
    private const ROLES = [
        'system_admin' => 'System Administrator',
        'event_manager' => 'Event Manager',
        'station_captain' => 'Station Captain',
        'operator' => 'Operator',
    ];

    /**
     * Cookie that marks a visitor who joined someone else's sandbox through
     * an invite link. It holds "sandbox UUID|user ID" for the account created
     * for them, and is encrypted so a guest cannot sign in as someone else.
     */
    public const GUEST_COOKIE = 'demo_guest';

    public function landing(): View
    {
        abort_unless(config('demo.enabled'), 404);

        return view('demo.landing');
    }

    public function provision(Request $request, DemoDatabasePool $pool): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $validated = $request->validate([
            'role' => 'required|in:operator,station_captain,event_manager,system_admin',
            'event_type' => 'required|in:FD,WFD',
            'attempt' => 'nullable|integer|min:0|max:'.self::MAX_PROVISION_ATTEMPTS,
        ]);

        $currentCount = $this->countDemoSessions($pool);

        if ($currentCount >= config('demo.max_sessions', 25)) {
            return redirect()->route('demo.landing')
                ->with('error', 'Demo capacity is currently full. Please try again in a few minutes.');
        }

        $slot = $pool->acquireProvisioningSlot();

        if ($slot === null) {
            return $this->retryProvisionLater($validated);
        }

        try {
            $uuid = $pool->claim() ?? $pool->createDatabase();
            $dbName = DemoDatabasePool::databaseName($uuid);

            $pool->migrate($uuid);

            // `db:seed` takes no custom arguments, so the chosen event type is
            // handed to the seeder through the container. Running it via Artisan
            // (rather than resolving the seeder here) keeps the console command
            // wired up that the shared reference seeders write their output to.
            app()->instance(DemoSeeder::EVENT_TYPE_KEY, $validated['event_type']);

            // `--database` is required: without it the seeder writes every model
            // to the default connection, filling the shared app database instead
            // of the visitor's freshly provisioned sandbox.
            Artisan::call('db:seed', [
                '--class' => DemoSeeder::class,
                '--database' => 'demo',
                '--force' => true,
                '--no-interaction' => true,
            ]);
        } finally {
            $slot->release();
        }

        $seededSessionIds = DB::connection('demo')
            ->table('operating_sessions')
            ->join('stations', 'stations.id', '=', 'operating_sessions.station_id')
            ->whereNull('operating_sessions.end_time')
            ->where('stations.is_gota', false)
            ->pluck('operating_sessions.id')
            ->all();

        DemoSimulateActivity::registerSeededSessions(
            $dbName,
            $seededSessionIds,
            (int) config('demo.ttl_hours', 24),
        );

        DemoSimulateActivity::registerSeededStations(
            $dbName,
            DB::connection('demo')
                ->table('stations')
                ->where('is_gota', false)
                ->where('is_vhf_only', false)
                ->pluck('id')
                ->all(),
            (int) config('demo.ttl_hours', 24),
        );

        // Spatie caches role and permission IDs in the shared default cache
        // store (Redis in production). Those IDs are per-database, so a cache
        // populated by another visitor's sandbox resolves to IDs that do not
        // exist in this one and the role lookup below finds nothing.
        // DemoMiddleware flushes this on later requests, but it deliberately
        // skips the provisioning route, so flush it here too.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The visitor signs in as one of these personas, so their shifts are
        // left for the visitor to check in and out themselves.
        $personaUserIds = collect(['operator', 'station_captain', 'event_manager', 'system_admin'])
            ->map(fn (string $role): int => $this->resolveUserForRole($role)->id)
            ->all();

        DemoSimulateActivity::registerSeededShiftAssignments(
            $dbName,
            DB::connection('demo')
                ->table('shift_assignments')
                ->whereNotIn('user_id', $personaUserIds)
                ->pluck('id')
                ->all(),
            (int) config('demo.ttl_hours', 24),
        );

        $user = $this->resolveUserForRole($request->role);
        Auth::login($user);

        session(['dev_role_override' => $user->roles->first()?->name ?? '']);

        DemoSession::create([
            'session_uuid' => $uuid,
            'role' => $request->role,
            'event_type' => $validated['event_type'],
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

        return redirect('/')->withCookie($cookie)->withoutCookie(self::GUEST_COOKIE);
    }

    /**
     * Show the page a visitor lands on from an invite link, where they pick
     * which role to join the shared sandbox as.
     */
    public function showJoin(Request $request, string $token): View|RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $demoSession = $this->findInvitedSession($token);

        if ($demoSession === null) {
            return $this->invalidInvite();
        }

        if ($this->visitorSessionUuid($request) === $demoSession->session_uuid) {
            return redirect('/');
        }

        return view('demo.join', [
            'token' => $token,
            'demoSession' => $demoSession,
            'maxParticipants' => DemoSession::maxParticipants(),
        ]);
    }

    /**
     * Sign a visitor into the sandbox they were invited to, provided it still
     * has room under the per-session user limit.
     */
    public function join(Request $request, string $token): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $validated = $request->validate([
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
            'first_name' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'call_sign' => ['nullable', 'string', 'regex:/^[A-Za-z0-9\/]{3,12}$/'],
        ], [
            'call_sign.regex' => 'Enter a call sign of 3–12 letters, numbers or slashes, or leave it blank.',
        ]);

        $demoSession = $this->findInvitedSession($token);

        if ($demoSession === null) {
            return $this->invalidInvite();
        }

        $previousUuid = $this->visitorSessionUuid($request);

        if ($previousUuid === $demoSession->session_uuid) {
            return redirect('/');
        }

        try {
            DemoDatabase::activate(DemoDatabasePool::databaseName($demoSession->session_uuid));

            $callSign = $this->guestCallSign($validated['call_sign'] ?? null);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            return $this->invalidInvite();
        }

        if (! $demoSession->claimSeat()) {
            return redirect()->route('demo.join', $token)
                ->with('error', 'This sandbox is full. Ask the person who invited you to free up a spot, or start your own demo.');
        }

        try {
            $user = $this->createGuestUser($validated, $callSign);
        } catch (\Throwable) {
            $demoSession->releaseSeat();

            return $this->invalidInvite();
        }

        // A visitor leaving another sandbox for this one gives up their seat there.
        if ($previousUuid !== null) {
            DemoSession::where('session_uuid', $previousUuid)->first()?->releaseSeat();
        }

        Auth::login($user);
        $request->session()->regenerate();

        session(['dev_role_override' => $user->roles->first()?->name ?? '']);

        try {
            DemoEvent::create([
                'demo_session_id' => $demoSession->id,
                'type' => 'action',
                'name' => 'session.guest_joined',
                'metadata' => [
                    'role' => $validated['role'],
                    'participant_count' => $demoSession->participant_count,
                ],
            ]);
        } catch (\Throwable) {
            // Analytics tracking is best-effort; don't block the join.
        }

        $minutes = max(1, (int) ceil(now()->diffInMinutes($demoSession->expires_at, true)));

        $sessionCookie = cookie(
            'demo_session',
            $demoSession->session_uuid.'|'.$validated['role'],
            $minutes,
            '/',
            null,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'Lax'
        );

        $guestCookie = cookie(
            self::GUEST_COOKIE,
            $demoSession->session_uuid.'|'.$user->id,
            $minutes,
            '/',
            null,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'Lax'
        );

        return redirect('/')->withCookie($sessionCookie)->withCookie($guestCookie);
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $uuid = $this->visitorSessionUuid($request);
        [$guestUuid] = explode('|', (string) $request->cookie(self::GUEST_COOKIE), 2);
        $isGuest = $uuid !== null && $guestUuid === $uuid;

        try {
            if ($uuid !== null) {
                $demoSession = DemoSession::where('session_uuid', $uuid)->first();
                if ($demoSession) {
                    DemoEvent::create([
                        'demo_session_id' => $demoSession->id,
                        'type' => 'action',
                        'name' => $isGuest ? 'session.guest_left' : 'session.reset',
                        'metadata' => ['role' => $demoSession->role],
                    ]);

                    // A guest leaving does not reset the sandbox for everyone else.
                    if (! $isGuest) {
                        $demoSession->update([
                            'was_reset' => true,
                            'last_seen_at' => now(),
                        ]);
                    }

                    $demoSession->releaseSeat();
                }
            }
        } catch (\Throwable) {
            // Analytics tracking is best-effort; don't block the reset.
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('demo.landing')
            ->withoutCookie('demo_session')
            ->withoutCookie(self::GUEST_COOKIE);
    }

    /**
     * Send a visitor who found every provisioning slot busy back to the
     * landing page, which resubmits their choice after a short pause. Waiting
     * in the browser rather than in this request keeps web workers free for
     * visitors already exploring their sandbox.
     *
     * @param  array{role: string, event_type: string, attempt?: int|null}  $validated
     */
    private function retryProvisionLater(array $validated): RedirectResponse
    {
        $attempt = (int) ($validated['attempt'] ?? 0) + 1;

        if ($attempt > self::MAX_PROVISION_ATTEMPTS) {
            return redirect()->route('demo.landing')
                ->with('error', 'The demo is very busy right now. Please try again in a minute or two.');
        }

        return redirect()->route('demo.landing')->with('provision_retry', [
            'role' => $validated['role'],
            'event_type' => $validated['event_type'],
            'attempt' => $attempt,
        ]);
    }

    /**
     * Count visitor sandboxes. Pooled databases nobody has claimed yet are
     * left out so the pool never eats into the session cap.
     */
    private function countDemoSessions(DemoDatabasePool $pool): int
    {
        if (DB::getDriverName() !== 'mysql') {
            return 0;
        }

        $rows = DB::select(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'demo\_%'"
        );

        $pooled = $pool->pooledDatabaseNames();

        return count(array_filter($rows, function ($row) use ($pooled): bool {
            $name = $row->schema_name ?? $row->SCHEMA_NAME;

            return preg_match('/^demo_[a-f0-9_]{32,40}$/', $name) && ! in_array($name, $pooled, true);
        }));
    }

    /**
     * The sandbox UUID from the visitor's demo_session cookie, if it is valid.
     */
    private function visitorSessionUuid(Request $request): ?string
    {
        [$uuid] = explode('|', (string) $request->cookie('demo_session'), 2);

        return Str::isUuid($uuid) ? $uuid : null;
    }

    /**
     * The live sandbox an invite token belongs to, or null when the token is
     * unknown, invitations are off, or the sandbox has expired.
     */
    private function findInvitedSession(string $token): ?DemoSession
    {
        if (! DemoSession::invitesEnabled()) {
            return null;
        }

        $demoSession = DemoSession::where('invite_token', $token)->first();

        if ($demoSession === null || $demoSession->hasExpired()) {
            return null;
        }

        return $demoSession;
    }

    private function invalidInvite(): RedirectResponse
    {
        return redirect()->route('demo.landing')
            ->with('error', 'That invite link is no longer valid. The sandbox may have expired — start your own demo below.');
    }

    /**
     * The call sign a guest asked for, or a generated one when they left it
     * blank. Call signs must be unique within the sandbox they are joining.
     *
     * @throws ValidationException
     */
    private function guestCallSign(?string $requested): string
    {
        if ($requested !== null && $requested !== '') {
            $callSign = Str::upper($requested);

            if (User::where('call_sign', $callSign)->exists()) {
                throw ValidationException::withMessages([
                    'call_sign' => 'Someone in this sandbox already uses that call sign.',
                ]);
            }

            return $callSign;
        }

        do {
            $callSign = 'GUEST'.random_int(100, 999);
        } while (User::where('call_sign', $callSign)->exists());

        return $callSign;
    }

    /**
     * Give a guest their own account in the sandbox, holding the role they picked.
     *
     * @param  array{role: string, first_name: string, last_name: string}  $validated
     */
    private function createGuestUser(array $validated, string $callSign): User
    {
        $user = User::create([
            'call_sign' => $callSign,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => 'guest-'.Str::lower(Str::random(16)).'@demo.invalid',
            'password' => Hash::make(Str::random(40)),
            'email_verified_at' => now(),
        ]);

        $user->assignRole(self::ROLES[$validated['role']]);

        return $user;
    }

    private function resolveUserForRole(string $role): User
    {
        $spatieRoleName = self::ROLES[$role] ?? 'Operator';

        return User::role($spatieRoleName)->firstOrFail();
    }
}
