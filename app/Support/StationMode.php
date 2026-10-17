<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\OperatingSession;
use App\Models\StationDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Station mode lets an enrolled browser start walk-up logging sessions by
 * callsign. The browser holds a device cookie; a signed-in operator's session
 * holds the device id, which caps their permissions to {@see self::ALLOWED_PERMISSIONS}.
 */
class StationMode
{
    public const COOKIE = 'station_device';

    public const SESSION_KEY = 'station_mode_device_id';

    /** One year, in minutes. */
    public const COOKIE_LIFETIME = 525600;

    /** Seconds without operator activity before the idle warning appears. */
    public const IDLE_WARNING_AFTER_SECONDS = 900;

    /** Seconds the idle warning counts down before the session is ended. */
    public const IDLE_COUNTDOWN_SECONDS = 60;

    /** @var list<string> */
    public const ALLOWED_PERMISSIONS = ['log-contacts', 'view-stations'];

    /**
     * Route names a station-mode operator may visit.
     *
     * @var list<string>
     */
    public const ALLOWED_ROUTES = [
        'station-mode.*',
        'dashboard',
        'dashboard.alt',
        'dashboard.tv',
        'public.landing',
        'logging.station-select',
        'logging.session',
        'logging.contacts.store',
        'logbook.index',
        'section-map',
        'scoring.index',
        'guestbook.index',
        'gallery.index',
        'gallery.show',
        'gallery.thumb',
        'gallery.image',
        'stations.index',
        'schedule.index',
        'schedule.dashboard',
        'site-safety.index',
        'equipment.club',
        'weather.index',
        'logout',
        'logout.get',
        'demo.*',
        '*livewire.*',
    ];

    /**
     * Paths of unnamed framework routes a station-mode page relies on.
     *
     * @var list<string>
     */
    public const ALLOWED_UNNAMED_PATHS = [
        'broadcasting/auth',
    ];

    /**
     * Route names that stay reachable on an enrolled browser with nobody signed in.
     *
     * @var list<string>
     */
    public const KIOSK_GUEST_ROUTES = [
        'station-mode.*',
        'login',
        'login.store',
        'two-factor.*',
        'demo.*',
        '*livewire.*',
    ];

    /**
     * Enroll this browser for a station and queue the device cookie.
     */
    public static function enroll(int $stationId, ?int $enrolledByUserId, ?string $name = null): StationDevice
    {
        $token = Str::random(64);

        $device = StationDevice::create([
            'station_id' => $stationId,
            'token_hash' => StationDevice::hashToken($token),
            'name' => $name,
            'enrolled_by_user_id' => $enrolledByUserId,
        ]);

        Cookie::queue(Cookie::make(self::COOKIE, $token, self::COOKIE_LIFETIME));

        return $device;
    }

    /**
     * Remove the device cookie from this browser.
     */
    public static function forgetCookie(): void
    {
        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    /**
     * Resolve the active enrolled device for this browser from its cookie.
     */
    public static function deviceFromCookie(Request $request): ?StationDevice
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return null;
        }

        if (! $request->attributes->has('station_mode.cookie_device')) {
            $request->attributes->set(
                'station_mode.cookie_device',
                StationDevice::query()->active()->with('station')->where('token_hash', StationDevice::hashToken($token))->first()
            );
        }

        return $request->attributes->get('station_mode.cookie_device');
    }

    /**
     * Whether the current request belongs to a signed-in station-mode session.
     */
    public static function isActive(): bool
    {
        return app()->bound('session')
            && session()->has(self::SESSION_KEY)
            && Auth::check();
    }

    /**
     * Get the device the current station-mode session is bound to.
     */
    public static function currentDevice(): ?StationDevice
    {
        if (! self::isActive()) {
            return null;
        }

        $request = request();

        if (! $request->attributes->has('station_mode.session_device')) {
            $request->attributes->set(
                'station_mode.session_device',
                StationDevice::query()->with('station')->find(session()->get(self::SESSION_KEY))
            );
        }

        return $request->attributes->get('station_mode.session_device');
    }

    /**
     * Whether the current request may operate the given station. Outside
     * station mode every station is allowed; inside it only the bound one.
     */
    public static function allowsStation(?int $stationId): bool
    {
        return ! self::isActive() || ($stationId !== null && $stationId === self::boundStationId());
    }

    /**
     * Whether a station-mode session may use this ability. Allowed permissions
     * and read-only policy abilities defer to the normal checks; everything
     * else, including owner-based policy writes, is denied.
     */
    public static function permitsAbility(string $ability): bool
    {
        return in_array($ability, self::ALLOWED_PERMISSIONS, true) || str_starts_with($ability, 'view');
    }

    /**
     * Get the station id the current station-mode session is bound to.
     */
    public static function boundStationId(): ?int
    {
        return self::currentDevice()?->station_id;
    }

    /**
     * Developer-mode overrides that must not carry over to a walk-up operator.
     *
     * @var list<string>
     */
    public const DEV_OVERRIDE_SESSION_KEYS = ['dev_role_override', 'dev_callsign_override'];

    /**
     * Sign the operator in for this device.
     */
    public static function startSession(Request $request, StationDevice $device, int $userId): void
    {
        session()->forget(self::DEV_OVERRIDE_SESSION_KEYS);
        Auth::loginUsingId($userId);
        session()->regenerate();
        session()->put(self::SESSION_KEY, $device->id);

        AuditLog::log(
            action: 'station_mode.session_started',
            userId: $userId,
            auditable: $device->station,
            newValues: ['device_id' => $device->id],
        );
    }

    /**
     * End the operator's logging session and sign them out, keeping the device cookie.
     */
    public static function endSession(Request $request): void
    {
        $user = $request->user();
        $device = self::currentDevice();

        if ($user) {
            OperatingSession::query()
                ->active()
                ->forUser($user->id)
                ->whereNull('external_source')
                ->update(['end_time' => appNow()]);

            AuditLog::log(
                action: 'station_mode.session_ended',
                userId: $user->id,
                auditable: $device?->station,
                newValues: ['device_id' => $device?->id],
            );
        }

        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();
        $request->attributes->remove('station_mode.session_device');
    }
}
