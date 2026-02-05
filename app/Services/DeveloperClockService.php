<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Service for managing fake time in development environments.
 *
 * This service allows developers to override the application's current time
 * for testing time-dependent features. Time can be frozen at a specific moment
 * or allowed to flow from a fake starting point.
 */
class DeveloperClockService
{
    /**
     * Set a fake time for the application.
     *
     * @param  Carbon  $time  The fake time to set
     * @param  bool  $frozen  Whether time should be frozen (true) or flow from this point (false)
     */
    public function setFakeTime(Carbon $time, bool $frozen = true): void
    {
        Setting::set('dev.fake_time', $time->toIso8601String());
        Setting::set('dev.time_frozen', $frozen ? '1' : '0');
        Setting::set('dev.fake_time_set_at', now()->toIso8601String());
    }

    /**
     * Clear the fake time override and restore normal time.
     */
    public function clearFakeTime(): void
    {
        Setting::set('dev.fake_time', null);
        Setting::set('dev.time_frozen', null);
        Setting::set('dev.fake_time_set_at', null);
        Date::setTestNow(null);
    }

    /**
     * Get the current fake time if set.
     *
     * @return Carbon|null The fake time or null if not set
     */
    public function getFakeTime(): ?Carbon
    {
        $fakeTime = Setting::get('dev.fake_time');

        if ($fakeTime === null) {
            return null;
        }

        return Carbon::parse($fakeTime);
    }

    /**
     * Check if time is frozen.
     *
     * @return bool True if time is frozen, false if it's flowing
     */
    public function isFrozen(): bool
    {
        return Setting::getBoolean('dev.time_frozen', true);
    }

    /**
     * Check if developer clock features are enabled.
     *
     * Developer clock is only enabled if:
     * - The developer.enabled config is true
     * - The application is not in production environment
     *
     * @return bool True if developer clock features are enabled
     */
    public function isEnabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return config('developer.enabled', false);
    }

    /**
     * Get the effective "now" time for application features.
     *
     * This returns the fake time (if set) for use in application-level
     * features like event countdowns and status calculations. It does NOT
     * affect system functions like CSRF tokens, sessions, or cache expiration.
     *
     * Use this method instead of now() when you want developer time override.
     */
    public function now(): Carbon
    {
        if (! $this->isEnabled()) {
            return Carbon::now();
        }

        $fakeTime = $this->getFakeTime();

        if ($fakeTime === null) {
            return Carbon::now();
        }

        if ($this->isFrozen()) {
            return $fakeTime->copy();
        }

        // Calculate flowing time
        $setAt = Setting::get('dev.fake_time_set_at');
        if ($setAt === null) {
            // Fallback to frozen if we don't have the set time
            return $fakeTime->copy();
        }

        $realSetAt = Carbon::parse($setAt);
        $elapsedSeconds = $realSetAt->diffInSeconds(Carbon::now());

        return $fakeTime->copy()->addSeconds($elapsedSeconds);
    }

    /**
     * Check if there's an active time override.
     */
    public function hasTimeOverride(): bool
    {
        return $this->isEnabled() && $this->getFakeTime() !== null;
    }
}
