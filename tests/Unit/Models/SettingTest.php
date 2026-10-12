<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();
});

it('returns the default when the setting is missing', function () {
    expect(Setting::get('missing.key', 'fallback'))->toBe('fallback');
    expect(Setting::get('missing.key'))->toBeNull();
});

it('does not cache the default, so later calls with other defaults still resolve', function () {
    expect(Setting::get('weather.forecast', []))->toBe([]);

    // Previously the [] default was cached and then json_decode()'d, throwing a TypeError.
    expect(Setting::get('weather.forecast'))->toBeNull();
    expect(Setting::get('weather.forecast', 'other'))->toBe('other');
});

it('decodes stored JSON values', function () {
    Setting::set('weather.forecast', ['latitude' => 1.5, 'longitude' => -2.5]);

    expect(Setting::get('weather.forecast'))->toBe(['latitude' => 1.5, 'longitude' => -2.5]);
});

it('returns plain string values unchanged', function () {
    Setting::set('site_name', 'Field Day Commander');

    expect(Setting::get('site_name', 'Default'))->toBe('Field Day Commander');
});

it('returns the default again after the setting is deleted', function () {
    Setting::set('weather.forecast', ['a' => 1]);
    expect(Setting::get('weather.forecast'))->toBe(['a' => 1]);

    Setting::set('weather.forecast', null);
    expect(Setting::get('weather.forecast', []))->toBe([]);
});

it('caches a missing setting so repeated reads do not re-query the database', function () {
    Setting::get('dev.fake_time');

    DB::enableQueryLog();
    Setting::get('dev.fake_time');
    Setting::get('dev.fake_time', 'fallback');

    expect(DB::getQueryLog())->toBeEmpty();
});

it('returns each caller its own default for a cached missing setting', function () {
    expect(Setting::get('weather.location', []))->toBe([])
        ->and(Setting::get('weather.location', 'fallback'))->toBe('fallback')
        ->and(Setting::get('weather.location'))->toBeNull();
});

it('picks up a setting created after a cached miss', function () {
    expect(Setting::get('timezone'))->toBeNull();

    Setting::set('timezone', 'America/New_York');

    expect(Setting::get('timezone'))->toBe('America/New_York');
});

it('reads raw values cached by earlier versions', function () {
    Cache::put('setting.site_name', 'Legacy Cached Name', 3600);

    expect(Setting::get('site_name'))->toBe('Legacy Cached Name');
});
