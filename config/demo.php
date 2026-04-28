<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, each visitor receives an isolated MySQL database provisioned
    | on demand. This should NEVER be enabled in production environments.
    |
    */

    'enabled' => env('DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Session TTL
    |--------------------------------------------------------------------------
    |
    | How many hours a demo session lives before cleanup removes its database.
    |
    */

    'ttl_hours' => (int) env('DEMO_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Maximum Concurrent Sessions
    |--------------------------------------------------------------------------
    |
    | The maximum number of active demo_* databases allowed at one time.
    | Requests to provision beyond this cap receive a "try again" message.
    |
    */

    'max_sessions' => (int) env('DEMO_MAX_SESSIONS', 25),

    /*
    |--------------------------------------------------------------------------
    | Analytics Retention
    |--------------------------------------------------------------------------
    |
    | How many days to keep demo analytics data before pruning.
    |
    */

    'analytics_retention_days' => (int) env('DEMO_ANALYTICS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Simulator Cache Store
    |--------------------------------------------------------------------------
    |
    | Cache store used by the activity simulator to remember which operating
    | sessions it owns per demo database. Must be a store that lives outside
    | the demo databases themselves (Redis in production, array in tests) so
    | that the simulator does not pollute sessions started by demo visitors.
    |
    */

    'simulator_cache_store' => env('DEMO_SIMULATOR_CACHE_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Weather
    |--------------------------------------------------------------------------
    |
    | Shared weather location and cache settings for demo sessions. Requires
    | a shared cache driver (Redis, Memcached, or database) in production —
    | `file` / `array` drivers are per-node and break cross-session visibility.
    |
    */

    'weather' => [
        'latitude' => env('DEMO_WEATHER_LAT', 41.8781),
        'longitude' => env('DEMO_WEATHER_LON', -87.6298),
        'state' => env('DEMO_WEATHER_STATE', 'IL'),
        'cache_ttl_minutes' => (int) env('DEMO_WEATHER_CACHE_TTL', 30),
    ],

];
