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

    'ttl_hours' => env('DEMO_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Maximum Concurrent Sessions
    |--------------------------------------------------------------------------
    |
    | The maximum number of active demo_* databases allowed at one time.
    | Requests to provision beyond this cap receive a "try again" message.
    |
    */

    'max_sessions' => env('DEMO_MAX_SESSIONS', 25),

    /*
    |--------------------------------------------------------------------------
    | Analytics Retention
    |--------------------------------------------------------------------------
    |
    | How many days to keep demo analytics data before pruning.
    |
    */

    'analytics_retention_days' => env('DEMO_ANALYTICS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Weather
    |--------------------------------------------------------------------------
    |
    | Shared weather location and cache settings for demo sessions.
    |
    */

    'weather' => [
        'latitude' => env('DEMO_WEATHER_LAT', 41.8781),
        'longitude' => env('DEMO_WEATHER_LON', -87.6298),
        'state' => env('DEMO_WEATHER_STATE', 'IL'),
        'cache_ttl_minutes' => env('DEMO_WEATHER_CACHE_TTL', 30),
    ],

];
