<?php

use App\Models\Setting;
use App\Services\DeveloperClockService;
use Carbon\Carbon;

if (! function_exists('appNow')) {
    /**
     * Get the current application time, respecting developer mode time override.
     *
     * Use this instead of now() when you want the fake time to apply.
     * This does NOT affect system functions like CSRF, sessions, or cache.
     *
     * Examples of where to use appNow():
     * - Event countdown timers
     * - Event status calculations (upcoming, in_progress, completed)
     * - Display timestamps that should reflect the "fake" time
     *
     * Examples of where to use now():
     * - Audit logs (should always be real time)
     * - Database timestamps
     * - Anything security-related
     */
    function appNow(): Carbon
    {
        return app(DeveloperClockService::class)->now();
    }
}

if (! function_exists('localTimezone')) {
    /**
     * Get the active timezone for the current user (or system default).
     *
     * Uses the authenticated user's preferred timezone when set, otherwise
     * falls back to the system timezone setting.
     */
    function localTimezone(): string
    {
        return auth()->check() && auth()->user()->preferred_timezone
            ? auth()->user()->preferred_timezone
            : Setting::get('timezone', config('app.timezone'));
    }
}

if (! function_exists('toLocalTime')) {
    /**
     * Convert a UTC Carbon instance to the user's preferred timezone (or system default).
     *
     * Returns the Carbon instance shifted to the display timezone so you can
     * call ->format() on it.  Append ->format('T') to get the abbreviation.
     *
     * @param  Carbon|string|null  $timestamp
     */
    function toLocalTime($timestamp): ?Carbon
    {
        if (! $timestamp) {
            return null;
        }

        $time = $timestamp instanceof Carbon ? $timestamp->copy() : Carbon::parse($timestamp);

        $timezone = auth()->check() && auth()->user()->preferred_timezone
            ? auth()->user()->preferred_timezone
            : Setting::get('timezone', config('app.timezone'));

        return $time->timezone($timezone);
    }
}

if (! function_exists('formatTimeAgo')) {
    /**
     * Format a timestamp as a human-readable "time ago" string.
     *
     * @param  Carbon|string|null  $timestamp
     */
    function formatTimeAgo($timestamp): string
    {
        if (! $timestamp) {
            return 'never';
        }

        $time = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);
        $diffInSeconds = appNow()->diffInSeconds($time);

        return match (true) {
            $diffInSeconds < 60 => 'just now',
            $diffInSeconds < 3600 => appNow()->diffInMinutes($time).'m ago',
            $diffInSeconds < 86400 => appNow()->diffInHours($time).'h ago',
            default => appNow()->diffInDays($time).'d ago',
        };
    }
}
