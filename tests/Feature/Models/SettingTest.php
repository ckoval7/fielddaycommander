<?php

use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Setting::query()->delete();
    Cache::flush();
});

test('can get and set a simple string setting', function () {
    Setting::set('test_key', 'test_value');

    expect(Setting::get('test_key'))->toBe('test_value');
});

test('can get and set an array setting', function () {
    $array = ['foo' => 'bar', 'baz' => 'qux'];
    Setting::set('array_key', $array);

    expect(Setting::get('array_key'))->toBe($array);
});

test('returns default value when setting does not exist', function () {
    expect(Setting::get('nonexistent', 'default'))->toBe('default');
});

test('returns null when setting does not exist and no default provided', function () {
    expect(Setting::get('nonexistent'))->toBeNull();
});

test('can convert setting to boolean', function () {
    Setting::set('bool_true', '1');
    Setting::set('bool_false', '0');
    Setting::set('bool_yes', 'yes');
    Setting::set('bool_no', 'no');

    expect(Setting::getBoolean('bool_true'))->toBeTrue()
        ->and(Setting::getBoolean('bool_false'))->toBeFalse()
        ->and(Setting::getBoolean('bool_yes'))->toBeTrue()
        ->and(Setting::getBoolean('bool_no'))->toBeFalse();
});

test('getBoolean returns default when setting does not exist', function () {
    expect(Setting::getBoolean('nonexistent', true))->toBeTrue()
        ->and(Setting::getBoolean('nonexistent', false))->toBeFalse();
});

test('setting uses cache', function () {
    Setting::set('cached_key', 'cached_value');

    // First call should hit database
    $value1 = Setting::get('cached_key');

    // Delete from database but should still get cached value
    Setting::query()->where('key', 'cached_key')->delete();
    $value2 = Setting::get('cached_key');

    expect($value1)->toBe('cached_value')
        ->and($value2)->toBe('cached_value');
});

test('setting cache is cleared when value is updated', function () {
    Setting::set('cache_test', 'original');
    Setting::get('cache_test'); // Prime the cache

    Setting::set('cache_test', 'updated');

    expect(Setting::get('cache_test'))->toBe('updated');
});

test('missing setting with non-null default does not poison cache for subsequent callers', function () {
    // First caller primes the cache with a non-null default
    expect(Setting::get('weather.location', []))->toBe([]);

    // Second caller with a different default must not receive the first default
    // and must not explode trying to json_decode it
    expect(Setting::get('weather.location'))->toBeNull();
});

test('tolerates legacy cache entries that hold non-string values', function () {
    // Simulate a cache entry written by the previous buggy version that cached
    // the caller's array default instead of the raw DB string.
    Cache::put('setting.weather.location', ['city' => 'Madison', 'state' => 'CT'], 3600);

    expect(Setting::get('weather.location'))->toBe(['city' => 'Madison', 'state' => 'CT']);
});

test('can get all settings', function () {
    Setting::set('key1', 'value1');
    Setting::set('key2', 'value2');
    Setting::set('key3', 'value3');

    $all = Setting::getAllSettings();

    expect($all)->toBeInstanceOf(Collection::class)
        ->and($all->count())->toBe(3)
        ->and($all->get('key1'))->toBe('value1')
        ->and($all->get('key2'))->toBe('value2')
        ->and($all->get('key3'))->toBe('value3');
});
