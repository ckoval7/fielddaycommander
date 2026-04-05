<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Branding
    |--------------------------------------------------------------------------
    |
    | These values are used when no active event is configured.
    |
    */

    'default_logo' => env('APP_LOGO', '/images/logo.png'),

    'default_callsign' => env('APP_CALLSIGN', config('app.name')),

    'default_tagline' => env('APP_TAGLINE', null),

];
