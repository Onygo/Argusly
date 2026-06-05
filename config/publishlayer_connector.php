<?php

return [
    'api' => [
        'base_url' => env('PL_CONNECTOR_BASE_URL', env('PUBLISHLAYER_BASE_URL', 'https://api.publishlayer.com')),
        'workspace_id' => env('PL_CONNECTOR_WORKSPACE_ID', env('PUBLISHLAYER_WORKSPACE_ID')),
        'api_key' => env('PL_CONNECTOR_API_KEY', env('PUBLISHLAYER_API_KEY')),
    ],

    // Legacy top-level aliases kept for backwards compatibility.
    'base_url' => env('PL_CONNECTOR_BASE_URL', env('PUBLISHLAYER_BASE_URL', 'https://api.publishlayer.com')),
    'api_key' => env('PL_CONNECTOR_API_KEY', env('PUBLISHLAYER_API_KEY')),
    'workspace_id' => env('PL_CONNECTOR_WORKSPACE_ID', env('PUBLISHLAYER_WORKSPACE_ID')),

    'timeout' => (int) env('PUBLISHLAYER_TIMEOUT', (int) env('PUBLISHLAYER_HTTP_TIMEOUT_SECONDS', 10)),
    'http_insecure_local' => (bool) env('PUBLISHLAYER_HTTP_INSECURE_LOCAL', false),
    'connections' => [
        'default' => [
            'api_key' => env('PL_CONNECTOR_API_KEY', env('PUBLISHLAYER_API_KEY')),
            'workspace_id' => env('PL_CONNECTOR_WORKSPACE_ID', env('PUBLISHLAYER_WORKSPACE_ID')),
            'base_url' => env('PL_CONNECTOR_BASE_URL', env('PUBLISHLAYER_BASE_URL', 'https://api.publishlayer.com')),
        ],
    ],
    'webhooks' => [
        'path' => env('PUBLISHLAYER_WEBHOOK_PATH', 'publishlayer/webhook'),
        'signing_secret' => env('PUBLISHLAYER_WEBHOOK_SECRET'),
        'header_name' => env('PUBLISHLAYER_WEBHOOK_SIGNATURE_HEADER', 'X-PublishLayer-Signature'),
        'id_header' => env('PUBLISHLAYER_WEBHOOK_EVENT_ID_HEADER', 'X-PublishLayer-Event-Id'),
        'timestamp_header' => env('PUBLISHLAYER_WEBHOOK_TIMESTAMP_HEADER', 'X-PublishLayer-Timestamp'),
        'tolerance_seconds' => (int) env('PUBLISHLAYER_WEBHOOK_TOLERANCE_SECONDS', 300),
        'idempotency_cache_ttl_seconds' => (int) env('PUBLISHLAYER_WEBHOOK_IDEMPOTENCY_TTL_SECONDS', 86400),
    ],
    'http' => [
        'timeout_seconds' => (int) env('PUBLISHLAYER_TIMEOUT', (int) env('PUBLISHLAYER_HTTP_TIMEOUT_SECONDS', 10)),
        'retries' => (int) env('PUBLISHLAYER_HTTP_RETRIES', 2),
        'retry_sleep_ms' => (int) env('PUBLISHLAYER_HTTP_RETRY_SLEEP_MS', 200),
    ],
    'public_blog' => [
        'use_connector' => (bool) env('PUBLISHLAYER_PUBLIC_BLOG_USE_CONNECTOR', true),
        'connector_endpoint' => env('PUBLISHLAYER_PUBLIC_BLOG_CONNECTOR_ENDPOINT', '/v1/public/blog/posts'),
        'fallback_to_local' => (bool) env('PUBLISHLAYER_PUBLIC_BLOG_FALLBACK_TO_LOCAL', true),
        'client_site_id' => env('PUBLISHLAYER_PUBLIC_BLOG_CLIENT_SITE_ID', ''),
        'workspace_id' => env('PUBLISHLAYER_PUBLIC_BLOG_WORKSPACE_ID', ''),
        'max_posts' => (int) env('PUBLISHLAYER_PUBLIC_BLOG_MAX_POSTS', 300),
    ],
];
