<?php

return [
    'api' => [
        'base_url' => env('ARGUSLY_CONNECTOR_BASE_URL', 'https://api.argusly.com'),
        'workspace_id' => env('ARGUSLY_CONNECTOR_WORKSPACE_ID'),
        'api_key' => env('ARGUSLY_CONNECTOR_API_KEY'),
    ],

    'base_url' => env('ARGUSLY_CONNECTOR_BASE_URL', 'https://api.argusly.com'),
    'api_key' => env('ARGUSLY_CONNECTOR_API_KEY'),
    'workspace_id' => env('ARGUSLY_CONNECTOR_WORKSPACE_ID'),

    'timeout' => (int) env('ARGUSLY_CONNECTOR_TIMEOUT', 10),
    'http_insecure_local' => (bool) env('ARGUSLY_HTTP_INSECURE_LOCAL', false),
    'connections' => [
        'default' => [
            'api_key' => env('ARGUSLY_CONNECTOR_API_KEY'),
            'workspace_id' => env('ARGUSLY_CONNECTOR_WORKSPACE_ID'),
            'base_url' => env('ARGUSLY_CONNECTOR_BASE_URL', 'https://api.argusly.com'),
        ],
    ],
    'webhooks' => [
        'path' => env('ARGUSLY_WEBHOOK_PATH', 'argusly/webhook'),
        'signing_secret' => env('ARGUSLY_WEBHOOK_SECRET'),
        'header_name' => env('ARGUSLY_WEBHOOK_SIGNATURE_HEADER', 'X-Argusly-Signature'),
        'id_header' => env('ARGUSLY_WEBHOOK_EVENT_ID_HEADER', 'X-Argusly-Event-Id'),
        'timestamp_header' => env('ARGUSLY_WEBHOOK_TIMESTAMP_HEADER', 'X-Argusly-Timestamp'),
        'tolerance_seconds' => (int) env('ARGUSLY_WEBHOOK_TOLERANCE_SECONDS', 300),
        'idempotency_cache_ttl_seconds' => (int) env('ARGUSLY_WEBHOOK_IDEMPOTENCY_TTL_SECONDS', 86400),
    ],
    'http' => [
        'timeout_seconds' => (int) env('ARGUSLY_HTTP_TIMEOUT_SECONDS', 10),
        'retries' => (int) env('ARGUSLY_HTTP_RETRIES', 2),
        'retry_sleep_ms' => (int) env('ARGUSLY_HTTP_RETRY_SLEEP_MS', 200),
    ],
    'public_blog' => [
        'use_connector' => (bool) env('ARGUSLY_PUBLIC_BLOG_USE_CONNECTOR', true),
        'connector_endpoint' => env('ARGUSLY_PUBLIC_BLOG_CONNECTOR_ENDPOINT', '/v1/public/blog/posts'),
        'fallback_to_local' => (bool) env('ARGUSLY_PUBLIC_BLOG_FALLBACK_TO_LOCAL', true),
        'client_site_id' => env('ARGUSLY_PUBLIC_BLOG_CLIENT_SITE_ID', ''),
        'workspace_id' => env('ARGUSLY_PUBLIC_BLOG_WORKSPACE_ID', ''),
        'max_posts' => (int) env('ARGUSLY_PUBLIC_BLOG_MAX_POSTS', 300),
    ],
];
