<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Widget Cache Service
 *
 * Provides caching layer for dashboard widgets with smart invalidation.
 * Uses Redis if available, falls back to file cache.
 * Supports tag-based invalidation for clearing related widgets.
 */
class WidgetCacheService
{
    /**
     * Cache key prefix for all widget cache entries.
     */
    protected const CACHE_PREFIX = 'dashboard:widget';

    /**
     * Default TTL in seconds (3 seconds).
     */
    protected const DEFAULT_TTL = 3;

    /**
     * Cache store to use (automatically detects Redis).
     */
    protected string $store;

    /**
     * Whether the cache store supports tagging.
     */
    protected bool $supportsTagging;

    /**
     * Create a new WidgetCacheService instance.
     */
    public function __construct()
    {
        // Use Redis if available, otherwise use default cache driver
        $this->store = config('cache.default') === 'redis' ? 'redis' : config('cache.default');

        // Check if the store supports tagging (Redis and Memcached do)
        $this->supportsTagging = in_array($this->store, ['redis', 'memcached']);
    }

    /**
     * Get a cached value or execute callback and cache the result.
     *
     * @param  string  $key  Cache key (will be prefixed automatically)
     * @param  Closure  $callback  Function to execute if cache miss
     * @param  int  $ttl  Time to live in seconds (default: 3)
     * @param  array<string>  $tags  Cache tags for invalidation (optional)
     */
    public function get(string $key, Closure $callback, int $ttl = self::DEFAULT_TTL, array $tags = []): mixed
    {
        $fullKey = $this->buildKey($key);

        try {
            // If tagging is supported and tags are provided, use tagged cache
            if ($this->supportsTagging && ! empty($tags)) {
                return Cache::store($this->store)
                    ->tags($this->prefixTags($tags))
                    ->remember($fullKey, $ttl, $callback);
            }

            // Otherwise, use regular cache
            return Cache::store($this->store)->remember($fullKey, $ttl, $callback);
        } catch (\Exception $e) {
            // If cache fails, log error and execute callback directly
            Log::warning('Widget cache get failed', [
                'key' => $fullKey,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * Invalidate cache entries matching a pattern.
     *
     * @param  string  $pattern  Pattern to match (supports wildcards)
     */
    public function invalidate(string $pattern): bool
    {
        $fullPattern = $this->buildKey($pattern);

        try {
            // For Redis, use SCAN to find and delete matching keys
            if ($this->store === 'redis') {
                $redis = Cache::store('redis')->getStore()->connection();
                $cursor = '0';
                $deleted = 0;

                do {
                    [$cursor, $keys] = $redis->scan($cursor, [
                        'MATCH' => $fullPattern,
                        'COUNT' => 100,
                    ]);

                    if (! empty($keys)) {
                        $redis->del($keys);
                        $deleted += count($keys);
                    }
                } while ($cursor !== '0');

                Log::info('Widget cache invalidated', [
                    'pattern' => $fullPattern,
                    'deleted' => $deleted,
                ]);

                return true;
            }

            // For file cache, we can't efficiently pattern-match
            // Log warning and return false
            Log::warning('Widget cache invalidation not fully supported', [
                'store' => $this->store,
                'pattern' => $fullPattern,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Widget cache invalidation failed', [
                'pattern' => $fullPattern,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Invalidate all cache entries with specific tags.
     *
     * @param  array<string>|string  $tags  Tag(s) to invalidate
     */
    public function invalidateByTags(array|string $tags): bool
    {
        if (! $this->supportsTagging) {
            Log::warning('Cache store does not support tagging', [
                'store' => $this->store,
            ]);

            return false;
        }

        $tags = is_array($tags) ? $tags : [$tags];

        try {
            Cache::store($this->store)
                ->tags($this->prefixTags($tags))
                ->flush();

            Log::info('Widget cache tags invalidated', [
                'tags' => $tags,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Widget cache tag invalidation failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Flush all widget cache entries.
     */
    public function flush(): bool
    {
        try {
            // If tagging is supported, flush all widget-tagged entries
            if ($this->supportsTagging) {
                Cache::store($this->store)
                    ->tags(['widgets'])
                    ->flush();
            } else {
                // Otherwise, use pattern matching for Redis
                if ($this->store === 'redis') {
                    $this->invalidate('*');
                } else {
                    // For other stores, flush the entire cache
                    // (this is not ideal but necessary without tagging)
                    Log::warning('Flushing entire cache store (no tagging support)', [
                        'store' => $this->store,
                    ]);
                    Cache::store($this->store)->flush();
                }
            }

            Log::info('Widget cache flushed');

            return true;
        } catch (\Exception $e) {
            Log::error('Widget cache flush failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build a cache key with proper prefix.
     *
     * Pattern: dashboard:widget:{type}:{config_hash}:{event_id}
     */
    public function buildKey(string $suffix): string
    {
        return self::CACHE_PREFIX.':'.$suffix;
    }

    /**
     * Build a widget-specific cache key.
     *
     * @param  string  $widgetType  Widget type (e.g., 'stat_card', 'chart')
     * @param  array<string, mixed>  $config  Widget configuration
     * @param  int|null  $eventId  Active event ID
     */
    public function buildWidgetKey(string $widgetType, array $config, ?int $eventId = null): string
    {
        // Create a hash of the config to ensure uniqueness
        $configHash = md5(json_encode($config));

        // Use current active event ID if not provided
        if ($eventId === null) {
            $eventId = \App\Models\Event::active()->value('id') ?? 0;
        }

        return "{$widgetType}:{$configHash}:{$eventId}";
    }

    /**
     * Get cache statistics (if available).
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        $stats = [
            'store' => $this->store,
            'supports_tagging' => $this->supportsTagging,
            'default_ttl' => self::DEFAULT_TTL,
        ];

        try {
            if ($this->store === 'redis') {
                $redis = Cache::store('redis')->getStore()->connection();
                $info = $redis->info('stats');

                $stats['redis_keys'] = $redis->dbSize();
                $stats['redis_hits'] = $info['keyspace_hits'] ?? null;
                $stats['redis_misses'] = $info['keyspace_misses'] ?? null;

                if ($stats['redis_hits'] && $stats['redis_misses']) {
                    $total = $stats['redis_hits'] + $stats['redis_misses'];
                    $stats['hit_rate'] = $total > 0
                        ? round(($stats['redis_hits'] / $total) * 100, 2).'%'
                        : '0%';
                }
            }
        } catch (\Exception $e) {
            $stats['error'] = $e->getMessage();
        }

        return $stats;
    }

    /**
     * Prefix tags with widget namespace.
     *
     * @param  array<string>  $tags
     * @return array<string>
     */
    protected function prefixTags(array $tags): array
    {
        // Always include the 'widgets' tag
        $prefixedTags = ['widgets'];

        foreach ($tags as $tag) {
            $prefixedTags[] = 'widget:'.$tag;
        }

        return $prefixedTags;
    }

    /**
     * Get recommended TTL for a widget type.
     */
    public function getRecommendedTtl(string $widgetType): int
    {
        return match ($widgetType) {
            'stat_card', 'progress_bar' => 3, // High-frequency updates
            'chart', 'list' => 5, // Moderate-frequency updates
            'timer' => 1, // Very high-frequency (but client-side)
            'info_card' => 60, // Low-frequency updates
            'feed' => 5, // Moderate-frequency updates
            default => self::DEFAULT_TTL,
        };
    }
}
