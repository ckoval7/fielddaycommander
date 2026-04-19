<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $table = 'system_config';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value with optional default
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember("setting.{$key}", 3600, function () use ($key) {
            return static::where('key', $key)->value('value');
        });

        if ($value === null) {
            return $default;
        }

        // Try to decode JSON values
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, mixed $value): void
    {
        // If value is null, delete the setting
        if ($value === null) {
            static::where('key', $key)->delete();
            Cache::forget("setting.{$key}");
            Cache::forget('settings.all');

            return;
        }

        if (is_array($value) || is_object($value)) {
            $storedValue = json_encode($value);
            if ($storedValue === false) {
                throw new \InvalidArgumentException("Failed to encode value for setting key: {$key}");
            }
        } else {
            $storedValue = $value;
        }
        static::updateOrInsert(
            ['key' => $key],
            ['value' => $storedValue, 'updated_at' => now(), 'created_at' => now()]
        );
        Cache::forget("setting.{$key}");
        Cache::forget('settings.all');
    }

    /**
     * Get a boolean setting
     */
    public static function getBoolean(string $key, bool $default = false): bool
    {
        $value = static::get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get all settings as key=>value collection
     */
    public static function getAllSettings(): Collection
    {
        return Cache::remember('settings.all', 3600, function () {
            return static::query()->pluck('value', 'key');
        });
    }

    /**
     * Clear all settings cache
     */
    public static function clearCache(): void
    {
        Cache::forget('settings.all');
        // Note: Individual setting caches (setting.{key}) are cleared in set() method
        // For bulk cache clearing, consider using cache tags in production
    }
}
