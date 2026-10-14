<?php

use App\Support\BroadcastChannels;
use Illuminate\Support\Facades\Broadcast;

/**
 * Dashboard event channel - authenticated users can listen for real-time updates.
 */
Broadcast::channel(BroadcastChannels::event('{eventId}'), function ($user) {
    return $user !== null;
});

/**
 * User-specific channel - only the matching user can listen.
 */
Broadcast::channel(BroadcastChannels::user('{id}'), function ($user, int $id) {
    return $user->id === $id;
});

/**
 * External logger status channel - authenticated users can listen for listener status changes.
 */
Broadcast::channel(BroadcastChannels::externalLogger('{eventConfigId}'), function ($user) {
    return $user !== null;
});
