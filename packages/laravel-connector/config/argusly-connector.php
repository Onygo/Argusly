<?php

declare(strict_types=1);

return [
    'api' => [
        'base_url' => env('ARGUSLY_CONNECTOR_API_URL', 'https://api.argusly.com'),
        'token' => env('ARGUSLY_CONNECTOR_API_KEY', env('ARGUSLY_CONNECTOR_TOKEN')),
        'workspace_id' => env('ARGUSLY_CONNECTOR_WORKSPACE_ID'),
        'timeout' => (int) env('ARGUSLY_CONNECTOR_TIMEOUT', 15),
    ],

    'site' => [
        'id' => env('ARGUSLY_CONNECTOR_SITE_ID'),
        'name' => env('ARGUSLY_CONNECTOR_SITE_NAME', env('APP_NAME')),
        'url' => env('ARGUSLY_CONNECTOR_SITE_URL', env('APP_URL')),
    ],

    'destination' => [
        'id' => env('ARGUSLY_CONNECTOR_DESTINATION_KEY', env('ARGUSLY_CONNECTOR_DESTINATION_ID')),
    ],

    'webhooks' => [
        'enabled' => (bool) env('ARGUSLY_CONNECTOR_WEBHOOKS_ENABLED', true),
        'secret' => env('ARGUSLY_CONNECTOR_WEBHOOK_SECRET'),
        'sync_path' => env('ARGUSLY_CONNECTOR_SYNC_PATH', 'argusly/sync'),
        'idempotency_ttl_seconds' => (int) env('ARGUSLY_CONNECTOR_IDEMPOTENCY_TTL_SECONDS', 86400),
    ],

    'policy' => [
        'allowed_operations' => array_filter(array_map('trim', explode(',', (string) env('ARGUSLY_CONNECTOR_ALLOWED_OPERATIONS', 'create,update,draft')))),
        'autonomous_allowed' => (bool) env('ARGUSLY_CONNECTOR_AUTONOMOUS_ALLOWED', false),
    ],
];
