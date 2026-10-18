<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Services\WeatherService;
use App\Support\DemoCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->cachePath = storage_path('framework/testing/demo-cache-'.uniqid());

    Config::set('demo.enabled', true);
    Config::set('cache.stores.file.path', $this->cachePath);
    Config::set('cache.stores.file.lock_path', $this->cachePath);
    Config::set('demo.shared_cache_store', 'file');
    Config::set('cache.default', 'file');
    Cache::forgetDriver('file');
});

afterEach(function () {
    File::deleteDirectory($this->cachePath);
});

test('demo sessions with the same cache key do not see each other\'s entries', function () {
    DemoCache::scopeTo('demo_aaaa');
    Cache::put('dashboard:widget:SectionsWorked:hash:1', 'session a');

    DemoCache::scopeTo('demo_bbbb');
    expect(Cache::get('dashboard:widget:SectionsWorked:hash:1'))->toBeNull();
    Cache::put('dashboard:widget:SectionsWorked:hash:1', 'session b');

    DemoCache::scopeTo('demo_aaaa');
    expect(Cache::get('dashboard:widget:SectionsWorked:hash:1'))->toBe('session a');
});

test('scoped entries stay out of the shared store', function () {
    DemoCache::scopeTo('demo_aaaa');
    Cache::put('setting.demo_provisioned_at', 'session a');

    expect(Cache::store('file')->get('setting.demo_provisioned_at'))->toBeNull();
});

test('forgetting a demo session removes only its cache directory', function () {
    DemoCache::scopeTo('demo_aaaa');
    Cache::put('key', 'session a');
    DemoCache::scopeTo('demo_bbbb');
    Cache::put('key', 'session b');

    DemoCache::forget('demo_aaaa');

    expect(File::isDirectory($this->cachePath.'/demo_aaaa'))->toBeFalse()
        ->and(Cache::get('key'))->toBe('session b');
});

test('non-file stores are scoped by a per-database key prefix', function () {
    Config::set('demo.shared_cache_store', 'redis');
    Config::set('cache.prefix', 'app-cache-');

    DemoCache::scopeTo('demo_aaaa');

    expect(config('cache.default'))->toBe(DemoCache::STORE)
        ->and(config('cache.stores.'.DemoCache::STORE.'.driver'))->toBe('redis')
        ->and(config('cache.stores.'.DemoCache::STORE.'.prefix'))->toBe('app-cache-demo_aaaa:');
});

test('demo weather reads the shared store from inside a scoped session', function () {
    Cache::store('file')->put('weather:demo:forecast', ['temperature' => 72], now()->addHour());

    DemoCache::scopeTo('demo_aaaa');

    expect(app(WeatherService::class)->getDisplayData()['data'])->toBe(['temperature' => 72]);
});
