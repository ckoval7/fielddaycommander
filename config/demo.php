<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
    | Users Per Session
    |--------------------------------------------------------------------------
    |
    | How many people may share one demo sandbox, counting the visitor who
    | provisioned it. Visitors invite others with a join link; once this many
    | people are in, the link stops admitting anyone new. Set to 1 to turn
    | invitations off.
    |
    */

    'max_users_per_session' => (int) env('DEMO_MAX_USERS_PER_SESSION', 5),

    /*
    |--------------------------------------------------------------------------
    | Database Pool
    |--------------------------------------------------------------------------
    |
    | Number of migrated, unclaimed demo databases kept ready by the
    | demo:fill-pool command. A visitor who claims one only waits for
    | seeding. Pooled databases do not count toward max_sessions.
    |
    */

    'pool_size' => (int) env('DEMO_POOL_SIZE', 5),

    /*
    |--------------------------------------------------------------------------
    | Provisioning Concurrency
    |--------------------------------------------------------------------------
    |
    | How many sandboxes may be built at once, and how many seconds a request
    | waits for a free slot. Each build holds a web worker, so this keeps a
    | burst of visitors from stalling the site. A visitor who still finds no
    | free slot is sent back to the landing page, which retries for them.
    |
    */

    'provision_concurrency' => (int) env('DEMO_PROVISION_CONCURRENCY', 2),

    'provision_wait_seconds' => (int) env('DEMO_PROVISION_WAIT_SECONDS', 3),

    /*
    |--------------------------------------------------------------------------
    | Analytics Retention
    |--------------------------------------------------------------------------
    |
    | How many days to keep demo analytics before pruning: sessions, their
    | events and the names and call signs participants entered.
    |
    */

    'analytics_retention_days' => (int) env('DEMO_ANALYTICS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Analytics Connection
    |--------------------------------------------------------------------------
    |
    | Database connection that stores demo sessions and events. It must be the
    | main database, not the per-visitor demo connection.
    |
    */

    'analytics_connection' => env('DEMO_ANALYTICS_CONNECTION', 'mysql'),

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
    | Shared Cache Store
    |--------------------------------------------------------------------------
    |
    | Store that demo sessions are scoped within. Each visitor's requests get
    | a per-database copy of it as their default store so cached widgets and
    | settings never leak between sessions. Data meant to be shared across
    | sessions (the demo weather feed) reads and writes this store directly.
    |
    */

    'shared_cache_store' => env('DEMO_SHARED_CACHE_STORE', env('CACHE_STORE', 'database')),

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
