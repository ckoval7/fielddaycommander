<?php

namespace App\Support;

/**
 * Single source of truth for broadcast channel names.
 *
 * Events, channel authorization (routes/channels.php) and Livewire Echo
 * listeners must all agree on a channel's name, so every one of them builds
 * it here. Passing a route placeholder such as '{eventId}' instead of an ID
 * produces the pattern used to register the channel's authorization callback.
 */
class BroadcastChannels
{
    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Private channel for live updates about an event (e.g. ContactLogged).
     */
    public static function event(int|string $eventId): string
    {
        return "event.{$eventId}";
    }

    /**
     * Private channel for a single user's notifications.
     */
    public static function user(int|string $userId): string
    {
        return "user.{$userId}";
    }

    /**
     * Private channel for an event configuration's external logger status and contacts.
     */
    public static function externalLogger(int|string $eventConfigurationId): string
    {
        return "event.{$eventConfigurationId}.external-logger";
    }

    /**
     * Public channel for weather alert changes.
     */
    public static function weather(): string
    {
        return 'weather';
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
}
