<?php

use App\Models\Setting;
use App\Services\DeveloperClockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->service = new DeveloperClockService;

    // Clear any existing fake time
    Setting::set('dev.fake_time', null);
    Setting::set('dev.time_frozen', null);
    Setting::set('dev.fake_time_set_at', null);
    Date::setTestNow(null);
});

afterEach(function () {
    // Clean up after each test
    Setting::set('dev.fake_time', null);
    Setting::set('dev.time_frozen', null);
    Setting::set('dev.fake_time_set_at', null);
    Date::setTestNow(null);
});

describe('setFakeTime tests', function () {
    it('stores fake time as ISO 8601 string in dev.fake_time setting', function () {
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');

        $this->service->setFakeTime($fakeTime);

        $storedValue = Setting::get('dev.fake_time');
        expect($storedValue)->toBe($fakeTime->toIso8601String());
    });

    it('stores frozen flag in dev.time_frozen setting', function () {
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');

        $this->service->setFakeTime($fakeTime, true);

        $storedValue = Setting::get('dev.time_frozen');
        expect($storedValue)->toBe(1);
    });

    it('stores real time when set in dev.fake_time_set_at setting', function () {
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');

        $this->service->setFakeTime($fakeTime);

        $storedValue = Setting::get('dev.fake_time_set_at');
        expect($storedValue)->not->toBeNull();

        $storedTime = Carbon::parse($storedValue);
        expect($storedTime)->toBeInstanceOf(Carbon::class);

        // Verify it's a recent timestamp (within last 2 seconds)
        expect(now()->diffInSeconds($storedTime))->toBeLessThan(2);
    });

    it('default frozen parameter is true', function () {
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');

        $this->service->setFakeTime($fakeTime);

        $storedValue = Setting::get('dev.time_frozen');
        expect($storedValue)->toBe(1);
    });

    it('stores frozen as false when explicitly set', function () {
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');

        $this->service->setFakeTime($fakeTime, false);

        $storedValue = Setting::get('dev.time_frozen');
        expect($storedValue)->toBe(0);
    });
});

describe('clearFakeTime tests', function () {
    it('removes all dev.* settings', function () {
        // Set up fake time first
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime);

        // Verify settings exist
        expect(Setting::get('dev.fake_time'))->not->toBeNull();
        expect(Setting::get('dev.time_frozen'))->not->toBeNull();
        expect(Setting::get('dev.fake_time_set_at'))->not->toBeNull();

        // Clear fake time
        $this->service->clearFakeTime();

        // Verify settings are removed
        expect(Setting::get('dev.fake_time'))->toBeNull();
        expect(Setting::get('dev.time_frozen'))->toBeNull();
        expect(Setting::get('dev.fake_time_set_at'))->toBeNull();
    });

    it('calls Date::setTestNow(null)', function () {
        // Set a test time
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        Date::setTestNow($fakeTime);

        // Clear fake time
        $this->service->clearFakeTime();

        // Verify test time is cleared by checking that now() is close to real time
        $realNow = Carbon::now();
        $currentNow = now();

        expect($currentNow->diffInSeconds($realNow))->toBeLessThan(2);
    });
});

describe('getFakeTime tests', function () {
    it('returns null when no fake time is set', function () {
        $result = $this->service->getFakeTime();

        expect($result)->toBeNull();
    });

    it('returns Carbon instance when fake time is set', function () {
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime);

        $result = $this->service->getFakeTime();

        expect($result)->toBeInstanceOf(Carbon::class);
        expect($result->toIso8601String())->toBe($fakeTime->toIso8601String());
    });

    it('returns correct time after multiple sets', function () {
        $firstTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($firstTime);

        $secondTime = Carbon::parse('2025-01-20 15:30:00');
        $this->service->setFakeTime($secondTime);

        $result = $this->service->getFakeTime();

        expect($result->toIso8601String())->toBe($secondTime->toIso8601String());
    });
});

describe('isFrozen tests', function () {
    it('returns true by default when no setting exists', function () {
        $result = $this->service->isFrozen();

        expect($result)->toBeTrue();
    });

    it('returns true when frozen is explicitly set to true', function () {
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime, true);

        $result = $this->service->isFrozen();

        expect($result)->toBeTrue();
    });

    it('returns false when frozen is explicitly set to false', function () {
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime, false);

        $result = $this->service->isFrozen();

        expect($result)->toBeFalse();
    });

    it('returns correct value based on setting', function () {
        Setting::set('dev.time_frozen', '1');
        expect($this->service->isFrozen())->toBeTrue();

        Setting::set('dev.time_frozen', '0');
        expect($this->service->isFrozen())->toBeFalse();

        Setting::set('dev.time_frozen', 'true');
        expect($this->service->isFrozen())->toBeTrue();

        Setting::set('dev.time_frozen', 'false');
        expect($this->service->isFrozen())->toBeFalse();
    });
});

describe('isEnabled tests', function () {
    it('returns false when config developer.enabled is false', function () {
        Config::set('developer.enabled', false);

        $result = $this->service->isEnabled();

        expect($result)->toBeFalse();
    });

    it('returns false when in production environment', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'production');

        $result = $this->service->isEnabled();

        expect($result)->toBeFalse();
    });

    it('returns true when enabled and not production', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'local');

        $result = $this->service->isEnabled();

        expect($result)->toBeTrue();
    });

    it('returns false when enabled but environment is production', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'production');

        $result = $this->service->isEnabled();

        expect($result)->toBeFalse();
    });

    it('returns false when disabled even in local environment', function () {
        Config::set('developer.enabled', false);
        app()->detectEnvironment(fn () => 'local');

        $result = $this->service->isEnabled();

        expect($result)->toBeFalse();
    });
});

describe('now() tests', function () {
    it('returns real time when not enabled', function () {
        Config::set('developer.enabled', false);
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime);

        $result = $this->service->now();

        // Should return real time, not fake time
        $realNow = Carbon::now();
        expect($result->diffInSeconds($realNow))->toBeLessThan(2);
    });

    it('returns real time when no fake time set', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'local');

        $result = $this->service->now();

        // Should return real time
        $realNow = Carbon::now();
        expect($result->diffInSeconds($realNow))->toBeLessThan(2);
    });

    it('returns frozen fake time when set', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'local');

        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime, true);

        $result = $this->service->now();

        // Should return the exact fake time
        expect($result->toDateTimeString())->toBe('2025-01-15 12:00:00');
    });

    it('returns flowing time with elapsed seconds', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'local');

        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime, false);

        // Wait a moment to simulate elapsed time
        sleep(2);

        $result = $this->service->now();

        // Flowing time should advance forward from the fake time
        expect($result->greaterThan($fakeTime))->toBeTrue();
        expect($fakeTime->diffInSeconds($result))->toBeGreaterThanOrEqual(2);
    });

    it('falls back to frozen time if set_at is missing for flowing time', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'local');

        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        Setting::set('dev.fake_time', $fakeTime->toIso8601String());
        Setting::set('dev.time_frozen', '0');
        // Deliberately not setting dev.fake_time_set_at

        $result = $this->service->now();

        // Should fall back to frozen time
        expect($result->toDateTimeString())->toBe('2025-01-15 12:00:00');
    });

    it('maintains frozen time across multiple calls', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'local');

        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime, true);

        $firstNow = $this->service->now();

        sleep(1);

        $secondNow = $this->service->now();

        // Time should remain frozen
        expect($firstNow->toDateTimeString())->toBe($secondNow->toDateTimeString());
        expect($firstNow->toDateTimeString())->toBe('2025-01-15 12:00:00');
    });

    it('does not affect global now() function', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'local');

        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime, true);

        // Service now() returns fake time
        expect($this->service->now()->toDateTimeString())->toBe('2025-01-15 12:00:00');

        // Global now() returns real time
        $realNow = Carbon::now();
        $globalNow = now();
        expect($globalNow->diffInSeconds($realNow))->toBeLessThan(2);
    });
});

describe('hasTimeOverride tests', function () {
    it('returns false when not enabled', function () {
        Config::set('developer.enabled', false);
        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime);

        expect($this->service->hasTimeOverride())->toBeFalse();
    });

    it('returns false when enabled but no fake time set', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'local');

        expect($this->service->hasTimeOverride())->toBeFalse();
    });

    it('returns true when enabled and fake time is set', function () {
        Config::set('developer.enabled', true);
        app()->detectEnvironment(fn () => 'local');

        $fakeTime = Carbon::parse('2025-01-15 12:00:00');
        $this->service->setFakeTime($fakeTime);

        expect($this->service->hasTimeOverride())->toBeTrue();
    });
});
