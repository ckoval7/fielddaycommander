<?php

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
];
