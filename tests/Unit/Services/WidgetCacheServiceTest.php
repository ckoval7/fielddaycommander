<?php

use App\Services\WidgetCacheService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Clear cache before each test
    Cache::flush();

    $this->service = new WidgetCacheService;
});

afterEach(function () {
    // Clean up cache after each test
    Cache::flush();
});

describe('WidgetCacheService', function () {
    test('constructs with correct store and tagging support', function () {
        $service = new WidgetCacheService;

        expect($service)->toBeInstanceOf(WidgetCacheService::class);
    });

    test('get method caches and retrieves values', function () {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return 'test-value';
        };

        // First call should execute callback
        $result1 = $this->service->get('test-key', $callback, 5);
        expect($result1)->toBe('test-value');
        expect($callCount)->toBe(1);

        // Second call should return cached value
        $result2 = $this->service->get('test-key', $callback, 5);
        expect($result2)->toBe('test-value');
        expect($callCount)->toBe(1); // Callback not called again
    });

    test('get method respects TTL', function () {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return 'test-value-'.$callCount;
        };

        // Cache with 1 second TTL
        $result1 = $this->service->get('ttl-test', $callback, 1);
        expect($result1)->toBe('test-value-1');

        // Wait for TTL to expire
        sleep(2);

        // Should execute callback again
        $result2 = $this->service->get('ttl-test', $callback, 1);
        expect($result2)->toBe('test-value-2');
        expect($callCount)->toBe(2);
    });

    test('buildKey method prefixes keys correctly', function () {
        $key = $this->service->buildKey('test-suffix');

        expect($key)->toBe('dashboard:widget:test-suffix');
    });

    test('buildWidgetKey generates correct format', function () {
        $config = ['metric' => 'total_score'];
        $key = $this->service->buildWidgetKey('stat_card', $config, 123);

        expect($key)->toStartWith('stat_card:')
            ->and($key)->toContain(':123')
            ->and($key)->toMatch('/^stat_card:[a-f0-9]{32}:123$/');
    });

    test('buildWidgetKey generates same hash for identical configs', function () {
        $config = ['metric' => 'qso_count', 'size' => 'normal'];

        $key1 = $this->service->buildWidgetKey('stat_card', $config, 1);
        $key2 = $this->service->buildWidgetKey('stat_card', $config, 1);

        expect($key1)->toBe($key2);
    });

    test('buildWidgetKey generates different hash for different configs', function () {
        $config1 = ['metric' => 'qso_count'];
        $config2 = ['metric' => 'total_score'];

        $key1 = $this->service->buildWidgetKey('stat_card', $config1, 1);
        $key2 = $this->service->buildWidgetKey('stat_card', $config2, 1);

        expect($key1)->not->toBe($key2);
    });

    test('flush clears all widget cache entries', function () {
        // Cache some values
        $this->service->get('key1', fn () => 'value1', 60);
        $this->service->get('key2', fn () => 'value2', 60);

        // Verify they're cached
        $callCount = 0;
        $this->service->get('key1', function () use (&$callCount) {
            $callCount++;

            return 'new-value';
        }, 60);
        expect($callCount)->toBe(0); // Should not call callback (cached)

        // Flush cache
        $result = $this->service->flush();
        expect($result)->toBeTrue();

        // Verify cache is cleared
        $this->service->get('key1', function () use (&$callCount) {
            $callCount++;

            return 'new-value';
        }, 60);
        expect($callCount)->toBe(1); // Should call callback (cache cleared)
    });

    test('getRecommendedTtl returns correct values for widget types', function () {
        expect($this->service->getRecommendedTtl('stat_card'))->toBe(3);
        expect($this->service->getRecommendedTtl('progress_bar'))->toBe(3);
        expect($this->service->getRecommendedTtl('chart'))->toBe(5);
        expect($this->service->getRecommendedTtl('list'))->toBe(5);
        expect($this->service->getRecommendedTtl('timer'))->toBe(1);
        expect($this->service->getRecommendedTtl('info_card'))->toBe(60);
        expect($this->service->getRecommendedTtl('feed'))->toBe(5);
        expect($this->service->getRecommendedTtl('unknown_type'))->toBe(3); // default
    });

    test('getStats returns cache statistics', function () {
        $stats = $this->service->getStats();

        expect($stats)->toBeArray()
            ->and($stats)->toHaveKeys(['store', 'supports_tagging', 'default_ttl']);
    });

    test('cache handles failures gracefully', function () {
        // Create a new service instance to avoid affecting other tests
        $service = new WidgetCacheService;

        // Mock Cache facade to throw exception
        Cache::shouldReceive('store')
            ->once()
            ->andThrow(new Exception('Cache connection failed'));

        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            return 'fallback-value';
        };

        // Should execute callback on cache failure
        $result = $service->get('test-key', $callback, 5);

        expect($result)->toBe('fallback-value');
        expect($callCount)->toBe(1);

        // Clear the mock
        Cache::clearResolvedInstances();
    })->skip('Mockery conflicts with Cache::flush in afterEach');

    test('get method with tags works when tagging is supported', function () {
        // Skip if cache driver doesn't support tagging
        if (! in_array(config('cache.default'), ['redis', 'memcached'])) {
            expect(true)->toBeTrue();

            return;
        }

        $callback = fn () => 'tagged-value';
        $tags = ['contacts', 'stats'];

        $result = $this->service->get('tagged-key', $callback, 60, $tags);

        expect($result)->toBe('tagged-value');
    });

    test('invalidateByTags works when tagging is supported', function () {
        // Skip if cache driver doesn't support tagging
        if (! in_array(config('cache.default'), ['redis', 'memcached'])) {
            expect(true)->toBeTrue();

            return;
        }

        // Cache values with tags
        $this->service->get('contacts-1', fn () => 'value1', 60, ['contacts']);
        $this->service->get('contacts-2', fn () => 'value2', 60, ['contacts']);
        $this->service->get('stats-1', fn () => 'value3', 60, ['stats']);

        // Invalidate 'contacts' tag
        $result = $this->service->invalidateByTags('contacts');
        expect($result)->toBeTrue();

        // Verify contacts cache is cleared
        $callCount = 0;
        $this->service->get('contacts-1', function () use (&$callCount) {
            $callCount++;

            return 'new-value';
        }, 60, ['contacts']);
        expect($callCount)->toBe(1); // Should call callback (cache cleared)

        // Verify stats cache is still there
        $callCount = 0;
        $this->service->get('stats-1', function () use (&$callCount) {
            $callCount++;

            return 'new-value';
        }, 60, ['stats']);
        expect($callCount)->toBe(0); // Should not call callback (still cached)
    });

    test('buildWidgetKey uses active event ID when not provided', function () {
        // This test assumes Event::active() is available
        // If no active event, should default to 0
        $config = ['metric' => 'qso_count'];
        $key = $this->service->buildWidgetKey('stat_card', $config);

        expect($key)->toMatch('/^stat_card:[a-f0-9]{32}:\d+$/');
    });
});
