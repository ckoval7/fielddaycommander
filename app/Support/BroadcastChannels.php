<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
}
