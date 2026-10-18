<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

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
