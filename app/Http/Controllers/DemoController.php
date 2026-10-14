<?php

namespace App\Http\Controllers;

use App\Console\Commands\DemoSimulateActivity;
use App\Models\DemoEvent;
use App\Models\DemoSession;
use App\Models\User;
use App\Services\DemoDatabasePool;
use App\Support\CallsignGenerator;
use App\Support\DemoCookie;
use App\Support\DemoDatabase;
use App\Support\NameGenerator;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
     * The optional name and call sign every visitor may give, whether they
     * start a sandbox or join one. A last name needs a first name with it.
     *
     * @var array<string, list<string>>
     */
    private const IDENTITY_RULES = [
        'first_name' => ['nullable', 'string', 'max:50', 'required_with:last_name'],
        'last_name' => ['nullable', 'string', 'max:50'],
        'call_sign' => ['nullable', 'string', 'regex:/^[A-Za-z0-9\/]{3,12}$/'],
    ];

    /**
     * @var array<string, string>
     */
    private const IDENTITY_MESSAGES = [
        'call_sign.regex' => 'Enter a call sign of 3–12 letters, numbers or slashes, or leave it blank.',
    ];

    /**
     * Domain of the placeholder email on every account a visitor signs in as.
     */
    private const PARTICIPANT_EMAIL_DOMAIN = 'demo.invalid';

    public function landing(): View
    {
        abort_unless(config('demo.enabled'), 404);

        return view('demo.landing');
    }

    public function provision(Request $request, DemoDatabasePool $pool): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $validated = $request->validate([
            'role' => ['required', Rule::in(array_keys(DemoCookie::ROLES))],
            'event_type' => 'required|in:FD,WFD',
            'attempt' => 'nullable|integer|min:0|max:'.self::MAX_PROVISION_ATTEMPTS,
            ...self::IDENTITY_RULES,
        ], self::IDENTITY_MESSAGES);

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

        // The visitor takes over the seeded persona for their role, keeping its
        // shifts and history, under the name and call sign they gave, if any.
        $user = $this->resolveUserForRole($request->role);
        $this->applyIdentity($user, $validated);

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

        $cookie = DemoCookie::make($uuid, $user->id, config('demo.ttl_hours', 24) * 60, $request->isSecure());

        return redirect('/')->withCookie($cookie);
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

        if (DemoCookie::fromRequest($request)?->uuid === $demoSession->session_uuid) {
            return redirect('/');
        }

        return view('demo.join', [
            'token' => $token,
            'demoSession' => $demoSession,
            'maxParticipants' => DemoSession::maxParticipants(),
        ]);
    }

    /**
     * Sign a visitor into the sandbox they were invited to, provided it has
     * not yet admitted as many people as the per-session user limit allows.
     */
    public function join(Request $request, string $token): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $validated = $request->validate([
            'role' => ['required', Rule::in(array_keys(DemoCookie::ROLES))],
            ...self::IDENTITY_RULES,
        ], self::IDENTITY_MESSAGES);

        $demoSession = $this->findInvitedSession($token);

        if ($demoSession === null) {
            return $this->invalidInvite();
        }

        if (DemoCookie::fromRequest($request)?->uuid === $demoSession->session_uuid) {
            return redirect('/');
        }

        DemoDatabase::activate(DemoDatabasePool::databaseName($demoSession->session_uuid));

        // The lock is scoped to the sandbox's cache, so two guests asking for
        // the same call sign at once cannot both be given it.
        try {
            $user = Cache::lock('demo-join', 10)->block(5, function () use ($validated, $demoSession): ?User {
                // Checked before claiming a call sign, which may rename a seeded member.
                if ($demoSession->refresh()->isFull()) {
                    return null;
                }

                $callSign = filled($validated['call_sign'] ?? null)
                    ? $this->claimCallSign($validated['call_sign'])
                    : $this->generatedCallSign();

                return $demoSession->claimSeat() ? $this->createGuestUser($validated, $callSign) : null;
            });
        } catch (QueryException) {
            // The sandbox's database is gone even though its session row is not.
            return $this->invalidInvite();
        }

        if ($user === null) {
            return redirect()->route('demo.join', $token)
                ->with('error', 'This sandbox is full. Start your own demo instead.');
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

        $cookie = DemoCookie::make($demoSession->session_uuid, $user->id, $minutes, $request->isSecure());

        return redirect('/')->withCookie($cookie);
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless(config('demo.enabled'), 404);

        $visitor = DemoCookie::fromRequest($request);

        try {
            if ($visitor !== null) {
                $demoSession = DemoSession::where('session_uuid', $visitor->uuid)->first();
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
            ->withoutCookie(DemoCookie::NAME);
    }

    /**
     * Send a visitor who found every provisioning slot busy back to the
     * landing page, which resubmits their choice after a short pause. Waiting
     * in the browser rather than in this request keeps web workers free for
     * visitors already exploring their sandbox.
     *
     * @param  array{role: string, event_type: string, attempt?: int|null, first_name?: string|null, last_name?: string|null, call_sign?: string|null}  $validated
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
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'call_sign' => $validated['call_sign'] ?? null,
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
     * Make the persona the visitor who started the sandbox now signs in as
     * theirs: under the name and call sign they gave, or the persona's own
     * when they left them blank.
     *
     * @param  array{first_name?: string|null, last_name?: string|null, call_sign?: string|null}  $validated
     *
     * @throws ValidationException
     */
    private function applyIdentity(User $user, array $validated): void
    {
        $changes = ['email' => self::participantEmail()];

        if (filled($validated['first_name'] ?? null)) {
            $changes['first_name'] = $validated['first_name'];
            $changes['last_name'] = $validated['last_name'] ?? '';
        }

        if (filled($validated['call_sign'] ?? null)) {
            $changes['call_sign'] = $this->claimCallSign($validated['call_sign'], $user);
        }

        $user->update($changes);
    }

    /**
     * Reserve the call sign a visitor asked for. A seeded member who happens
     * to hold it is given a new one, since a visitor's real call sign is
     * theirs; only another person in the sandbox can stand in the way.
     *
     * @throws ValidationException
     */
    private function claimCallSign(string $requested, ?User $claimant = null): string
    {
        $callSign = Str::upper($requested);

        if ($callSign === User::SYSTEM_CALL_SIGN) {
            throw ValidationException::withMessages([
                'call_sign' => 'That call sign is reserved. Enter your own, or leave it blank.',
            ]);
        }

        $holder = User::where('call_sign', $callSign)
            ->when($claimant, fn ($query) => $query->whereKeyNot($claimant->getKey()))
            ->first();

        if ($holder !== null && str_ends_with($holder->email, '@'.self::PARTICIPANT_EMAIL_DOMAIN)) {
            throw ValidationException::withMessages([
                'call_sign' => 'Someone in this sandbox already uses that call sign.',
            ]);
        }

        $holder?->update(['call_sign' => $this->generatedCallSign()]);

        return $callSign;
    }

    /**
     * A realistic call sign nobody in the sandbox holds yet.
     */
    private function generatedCallSign(): string
    {
        do {
            $callSign = CallsignGenerator::us();
        } while (User::where('call_sign', $callSign)->exists());

        return $callSign;
    }

    /**
     * Placeholder address for an account a visitor signs in as, which also
     * tells participants apart from the seeded members.
     */
    private static function participantEmail(): string
    {
        return 'visitor-'.Str::lower(Str::random(16)).'@'.self::PARTICIPANT_EMAIL_DOMAIN;
    }

    /**
     * Give a guest their own account in the sandbox, holding the role they
     * picked, with a made-up name when they gave none.
     *
     * @param  array{role: string, first_name?: string|null, last_name?: string|null}  $validated
     */
    private function createGuestUser(array $validated, string $callSign): User
    {
        [$firstName, $lastName] = filled($validated['first_name'] ?? null)
            ? [$validated['first_name'], $validated['last_name'] ?? '']
            : [NameGenerator::firstName(), NameGenerator::lastName()];

        $user = User::create([
            'call_sign' => $callSign,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => self::participantEmail(),
            'password' => Hash::make(Str::random(40)),
            'email_verified_at' => now(),
        ]);

        $user->assignRole(DemoCookie::roleName($validated['role']));

        return $user;
    }

    private function resolveUserForRole(string $role): User
    {
        return User::role(DemoCookie::roleName($role))->firstOrFail();
    }
}
