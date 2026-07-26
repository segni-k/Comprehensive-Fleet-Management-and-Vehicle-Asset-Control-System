<?php

return [
    'password' => [
        'minimum_length' => (int) env('IDENTITY_PASSWORD_MINIMUM_LENGTH', 12),
        'history_count' => (int) env('IDENTITY_PASSWORD_HISTORY_COUNT', 5),
        'expires_after_days' => (int) env('IDENTITY_PASSWORD_EXPIRES_AFTER_DAYS', 90),
    ],
    'lockout' => [
        'attempts' => (int) env('IDENTITY_LOCKOUT_ATTEMPTS', 5),
        'minutes' => (int) env('IDENTITY_LOCKOUT_MINUTES', 15),
    ],
    'sessions' => [
        'access_minutes' => (int) env('IDENTITY_ACCESS_TOKEN_MINUTES', 15),
        'refresh_days' => (int) env('IDENTITY_REFRESH_TOKEN_DAYS', 30),
        'trusted_hours' => (int) env('IDENTITY_TRUSTED_SESSION_HOURS', 12),
    ],
    'mfa' => [
        'issuer' => env('IDENTITY_MFA_ISSUER', 'Fleet Management System'),
        'window' => (int) env('IDENTITY_MFA_WINDOW', 1),
    ],
    'break_glass' => [
        'maximum_minutes' => (int) env('IDENTITY_BREAK_GLASS_MAXIMUM_MINUTES', 60),
    ],
];
