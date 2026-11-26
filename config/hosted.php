<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

return [

    /*
    |--------------------------------------------------------------------------
    | Hosted Mode
    |--------------------------------------------------------------------------
    |
    | On for a club whose instance is run for it by a hosting service, one
    | container per club. The service sets the club up with tenant:bootstrap
    | instead of the setup wizard, the person who signed the club up becomes
    | its System Administrator, and the SYSTEM account is kept for the
    | service's support staff: the club can't see it or sign in as it.
    |
    | Self-hosted installs leave this off.
    |
    */

    'enabled' => (bool) env('HOSTED_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Support Sign-in Key
    |--------------------------------------------------------------------------
    |
    | Shared secret the hosting service signs support sign-in links with
    | (HMAC-SHA256). Unset turns support sign-in off.
    |
    */

    'support_key' => env('HOSTED_SUPPORT_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Support Link Lifetime
    |--------------------------------------------------------------------------
    |
    | Longest a support sign-in link may stay valid, in minutes. A link whose
    | expiry is further away than this is refused, whatever it claims.
    |
    */

    'support_link_max_minutes' => (int) env('HOSTED_SUPPORT_LINK_MAX_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Bootstrap File
    |--------------------------------------------------------------------------
    |
    | JSON file the container entrypoint passes to tenant:bootstrap on boot.
    | The Helm chart mounts it from a Secret the hosting service writes.
    |
    */

    'bootstrap_file' => env('TENANT_BOOTSTRAP_FILE', '/run/fd-commander/bootstrap/tenant.json'),

];
