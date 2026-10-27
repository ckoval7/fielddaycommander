<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Setting;
use App\Services\DeveloperClockService;
use Carbon\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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

if (! function_exists('timeFormat')) {
    /**
     * Get the configured time format string for display (without seconds).
     *
     * Maps the stored time_format setting to a display-appropriate PHP date format.
     * Use this when formatting shift or event times for display.
     */
    function timeFormat(): string
    {
        return Setting::get('time_format', 'H:i:s') === 'H:i:s' ? 'H:i' : 'g:i A';
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
        $diffInSeconds = (int) $time->diffInSeconds(appNow());

        return match (true) {
            $diffInSeconds < 60 => 'just now',
            $diffInSeconds < 3600 => (int) $time->diffInMinutes(appNow()).'m ago',
            $diffInSeconds < 86400 => (int) $time->diffInHours(appNow()).'h ago',
            default => (int) $time->diffInDays(appNow()).'d ago',
        };
    }
}

if (! function_exists('spoken')) {
    /**
     * Show $visible on screen but give screen readers $say instead, for
     * short forms they misread ("N/A" as "N slash A", "5m" as "5 meters").
     */
    function spoken(string $visible, string $say): HtmlString
    {
        return new HtmlString('<span aria-hidden="true">'.e($visible).'</span><span class="sr-only">'.e($say).'</span>');
    }
}

if (! function_exists('spokenDuration')) {
    /**
     * Spell out the d/h/m/s units of a short duration: "2h 5m ago" becomes
     * "2 hours 5 minutes ago". Only for durations, since "20m" is also a band.
     */
    function spokenDuration(string $duration): string
    {
        $units = ['d' => 'day', 'h' => 'hour', 'm' => 'minute', 's' => 'second'];

        return preg_replace_callback(
            '/\b(\d+)([dhms])\b/',
            fn (array $match): string => $match[1].' '.Str::plural($units[$match[2]], (int) $match[1]),
            $duration,
        );
    }
}

if (! function_exists('spokenWatts')) {
    /**
     * A power level shown as "100W" but read as "100 watts".
     */
    function spokenWatts(int|float|string|null $watts): HtmlString
    {
        return spoken("{$watts}W", "{$watts} ".Str::plural('watt', (int) $watts));
    }
}

if (! function_exists('spokenAbbreviations')) {
    /**
     * Expand the short forms screen readers spell out or misread, for labels
     * that come from frozen rule sets ("QSO Base Pts", "Power Multi.") and
     * dashboard summaries ("12.5/hr", "45 Qs").
     */
    function spokenAbbreviations(string $text): string
    {
        return preg_replace(
            ['/\bPts\b/i', '/\bPt\b/i', '/\bMulti?\.(?=\s|$)/i', '/\bN\/A\b/', '/\/hr\b/', '/\bQs\b/'],
            ['points', 'point', 'multiplier', 'not available', ' per hour', 'contacts'],
            $text,
        );
    }
}
