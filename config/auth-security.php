<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

return [
    // Two-Factor Authentication
    '2fa_mode' => env('2FA_MODE', 'optional'), // required|optional|disabled

    // User Registration
    'registration_mode' => env('REGISTRATION_MODE', 'open'), // open|approval_required|email_verification_required|disabled

    // Password Reset
    'password_reset_method' => env('PASSWORD_RESET_METHOD', 'hybrid'), // email|admin_only|hybrid

    // Failed Login Security
    'failed_login_strategy' => env('FAILED_LOGIN_STRATEGY', 'progressive_delay'), // lockout|progressive_delay
    'lockout_threshold' => env('LOCKOUT_THRESHOLD', 5),
    'lockout_duration' => env('LOCKOUT_DURATION', 15), // minutes

    // Session Timeouts
    'session_timeout_admin' => env('SESSION_TIMEOUT_ADMIN', 30), // minutes
    'session_timeout_default' => env('SESSION_TIMEOUT_DEFAULT', 240), // minutes (4 hours)
    'event_duration_mode' => env('EVENT_DURATION_MODE', true),

    // Audit Logging
    'audit_logging_enabled' => env('AUDIT_LOGGING_ENABLED', true),
    'audit_retention_days' => env('AUDIT_RETENTION_DAYS', 365),

    // Password Requirements
    'password_min_length' => env('PASSWORD_MIN_LENGTH', 8),
    'password_admin_min_length' => env('PASSWORD_ADMIN_MIN_LENGTH', 12),

    // HTTP Strict Transport Security max-age in seconds, sent only over HTTPS.
    // 0 (default) leaves HSTS off: browsers that see it refuse plain HTTP and
    // self-signed certificates for that host until it expires, which can lock
    // field sites out. Set it (e.g. 31536000) only behind a trusted certificate.
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 0),

    // Lock the SYSTEM setup account once the club has a named System
    // Administrator: it can't sign in with a password, is left out of the
    // user list and can't be edited. It still opens through a one-time
    // sign-in link from `php artisan user:sign-in-link`.
    'system_account_locked' => (bool) env('SYSTEM_ACCOUNT_LOCKED', false),

    // One-time sign-in links (`php artisan user:sign-in-link`). The command
    // signs links with a key derived from APP_KEY. Set SIGN_IN_LINK_KEY to
    // also accept links signed by an outside service, such as a hosting
    // provider's support desk. A link can't be made to last longer than
    // sign_in_link_max_minutes.
    'sign_in_link_key' => env('SIGN_IN_LINK_KEY'),
    'sign_in_link_max_minutes' => (int) env('SIGN_IN_LINK_MAX_MINUTES', 10),
];
