<?php

$trustedProxies = env('TRUSTED_PROXIES', '');

return [
    'application_version' => env('APP_VERSION', '0.1.0'),
    'api_rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),
    'max_request_bytes' => (int) env('MAX_REQUEST_BYTES', 10_485_760),
    'trusted_proxies' => is_string($trustedProxies)
        ? array_values(array_filter(array_map('trim', explode(',', $trustedProxies))))
        : [],
    'health' => [
        'database' => (bool) env('HEALTH_CHECK_DATABASE', true),
        'redis' => (bool) env('HEALTH_CHECK_REDIS', false),
        'queue' => (bool) env('HEALTH_CHECK_QUEUE', false),
    ],
];
