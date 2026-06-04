<?php

return [
    'enabled' => env('ANALYTICS_ENABLED', true),

    'privacy' => [
        'salt' => env('ANALYTICS_SALT', ''),
        'respect_dnt' => env('ANALYTICS_RESPECT_DNT', true),
        'default_retention_days' => env('ANALYTICS_RETENTION_DAYS', 365),
    ],

    'ingestion' => [
        'max_events_per_batch' => 50,
        'max_string_length' => 2000,
        'max_payload_bytes' => 32768,
        'rate_limit_per_minute' => 120,
    ],

    'script' => [
        'cache_seconds' => 3600,
        'version' => env('ANALYTICS_SCRIPT_VERSION', '1.0.0'),
    ],

    'tracking' => [
        'engaged_after_seconds' => env('ANALYTICS_ENGAGED_AFTER_SECONDS', 10),
        'read_through_scroll_percent' => env('ANALYTICS_READ_THROUGH_SCROLL_PERCENT', 75),
        'read_through_fallback_seconds' => env('ANALYTICS_READ_THROUGH_FALLBACK_SECONDS', 20),
    ],
];
