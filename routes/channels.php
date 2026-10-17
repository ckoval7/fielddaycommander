<?php

use App\Support\BroadcastChannels;
use Illuminate\Support\Facades\Broadcast;

/**
 * Dashboard event channel - authenticated users can listen for real-time updates.
 */
Broadcast::channel(BroadcastChannels::event('{eventId}'), BroadcastChannels::authorize(function ($user) {
    return $user !== null;
}));

/**
 * User-specific channel - only the matching user can listen.
 */
Broadcast::channel(BroadcastChannels::user('{id}'), BroadcastChannels::authorize(function ($user, int $id) {
    return $user->id === $id && ! $user->isInStationMode();
}));

/**
 * External logger status channel - authenticated users can listen for listener status changes.
 */
Broadcast::channel(BroadcastChannels::externalLogger('{eventConfigId}'), BroadcastChannels::authorize(function ($user) {
    return $user !== null;
}));
