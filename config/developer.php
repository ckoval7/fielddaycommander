<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Developer Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, developer tools become available including time travel,
    | database reset/snapshot features, and debug utilities. This should
    | NEVER be enabled in production environments.
    |
    | Safety: If APP_ENV=production, developer mode is disabled regardless
    | of this setting.
    |
    */

    'enabled' => env('DEVELOPER_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Maximum Snapshots
    |--------------------------------------------------------------------------
    |
    | The maximum number of database snapshots to keep. When this limit is
    | exceeded, the oldest snapshots are automatically deleted.
    |
    */

    'max_snapshots' => 10,

    /*
    |--------------------------------------------------------------------------
    | Snapshot Storage Path
    |--------------------------------------------------------------------------
    |
    | The directory where database snapshots are stored. This path should
    | be writable by the web server.
    |
    */

    'snapshot_path' => storage_path('app/snapshots'),

];
