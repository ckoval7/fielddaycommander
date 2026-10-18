<?php

namespace App\Support;

use Closure;

/**
 * Single source of truth for broadcast channel names.
 *
 * Events, channel authorization (routes/channels.php) and Livewire Echo
 * listeners must all agree on a channel's name, so every one of them builds
 * it here. Passing a route placeholder such as '{eventId}' instead of an ID
 * produces the pattern used to register the channel's authorization callback.
 *
 * In demo mode every visitor database reuses the same IDs, so channels are
 * scoped to the active visitor ("demo.{scope}.event.1") to keep one visitor's
 * broadcasts from reaching every other visitor subscribed to event 1.
 */
class BroadcastChannels
{
    /**
     * Route placeholder for the visitor scope in demo channel patterns.
     */
    private const DEMO_SCOPE_PLACEHOLDER = '{demoScope}';

    /**
     * Broadcast name for a new contact on the event channel.
     */
    public const CONTACT_LOGGED = 'ContactLogged';

    /**
     * Broadcast name for an edited contact on the event channel.
     */
    public const CONTACT_UPDATED = 'ContactUpdated';

    /**
     * Broadcast name for a deleted contact on the event channel.
     */
    public const CONTACT_DELETED = 'ContactDeleted';

    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Private channel for live updates about an event (e.g. ContactLogged).
     */
    public static function event(int|string $eventId): string
    {
        return self::scoped("event.{$eventId}", self::isPlaceholder($eventId));
    }

    /**
     * Private channel for a single user's notifications.
     */
    public static function user(int|string $userId): string
    {
        return self::scoped("user.{$userId}", self::isPlaceholder($userId));
    }

    /**
     * Private channel for an event configuration's external logger status and contacts.
     */
    public static function externalLogger(int|string $eventConfigurationId): string
    {
        return self::scoped(
            "event.{$eventConfigurationId}.external-logger",
            self::isPlaceholder($eventConfigurationId),
        );
    }

    /**
     * Public channel for weather alert changes (scoped per visitor in demo mode).
     */
    public static function weather(): string
    {
        return self::scoped('weather', false);
    }

    /**
     * Public weather channel shared by every demo visitor.
     *
     * The demo's weather feed is fetched once for all visitors, so its alerts
     * broadcast here, while a visitor's manual alert stays on weather().
     * Outside demo mode this is the same channel as weather().
     */
    public static function sharedWeather(): string
    {
        return 'weather';
    }

    /**
     * Wrap a channel authorization callback for registration in routes/channels.php.
     *
     * In demo mode the visitor scope is the pattern's first parameter: it must
     * match the requesting visitor's own scope, and is then dropped so the
     * callback receives the same arguments as outside demo mode.
     */
    public static function authorize(Closure $callback): Closure
    {
        if (! config('demo.enabled')) {
            return $callback;
        }

        return function (mixed $user, string $demoScope, mixed ...$parameters) use ($callback): bool {
            $visitorScope = self::demoScope();

            return $visitorScope !== null
                && hash_equals($visitorScope, $demoScope)
                && (bool) $callback($user, ...$parameters);
        };
    }

    /**
     * Livewire listener key for an event on a private channel.
     *
     * Prefix the event name with a dot when the event defines broadcastAs().
     */
    public static function privateListener(string $channel, string $event): string
    {
        return "echo-private:{$channel},{$event}";
    }

    /**
     * Livewire listener key for an event on a public channel.
     */
    public static function publicListener(string $channel, string $event): string
    {
        return "echo:{$channel},{$event}";
    }

    /**
     * Livewire listener map that calls one method whenever a contact is
     * logged, edited or deleted for an event.
     *
     * @return array<string, string>
     */
    public static function contactChangeListeners(int|string $eventId, string $method): array
    {
        $channel = self::event($eventId);

        return collect([self::CONTACT_LOGGED, self::CONTACT_UPDATED, self::CONTACT_DELETED])
            ->mapWithKeys(fn (string $name): array => [self::privateListener($channel, ".{$name}") => $method])
            ->all();
    }

    /**
     * Prefix a channel name (or pattern) with the demo visitor scope.
     *
     * Names built outside a visitor's context (e.g. the scheduler's weather
     * fetch) stay unscoped.
     */
    private static function scoped(string $name, bool $isPattern): string
    {
        if (! config('demo.enabled')) {
            return $name;
        }

        if ($isPattern) {
            return 'demo.'.self::DEMO_SCOPE_PLACEHOLDER.'.'.$name;
        }

        $scope = self::demoScope();

        return $scope === null ? $name : "demo.{$scope}.{$name}";
    }

    /**
     * Opaque channel scope for the active visitor database.
     *
     * Derived with an HMAC so channel names never reveal the visitor's
     * database name, which is built from their demo session cookie.
     */
    private static function demoScope(): ?string
    {
        $dbName = DemoDatabase::current();

        return $dbName === null
            ? null
            : substr(hash_hmac('sha256', $dbName, (string) config('app.key')), 0, 24);
    }

    private static function isPlaceholder(int|string $value): bool
    {
        return is_string($value) && str_starts_with($value, '{');
    }
}
